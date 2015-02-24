<?php

$north = $_REQUEST['north'];
$south = $_REQUEST['south'];
$west  = $_REQUEST['west'];
$east  = $_REQUEST['east'];

$width = $east - $west;
$height = $north - $south;
$area = $width * $height;

$diagonal = sqrt(pow($width,2) + pow($height,2));
$zoom = 100 / $diagonal;
//$zoom  = $_REQUEST['zoom'] ?: 0.005;

$segmentlength = $width / 10;

header('Content-type: application/json');
$conn = pg_pconnect("host=192.168.24.15 dbname=research user=postgres password=postgres");
if (!$conn) {
  echo "A connection error occurred.\n";
  exit;
}
$query = "
WITH
bounds AS (
	SELECT ST_Segmentize(ST_MakeEnvelope($west, $south, $east, $north, 28992),$segmentlength) geom
),
footprints AS (
	SELECT nextval('counter') id, ogc_fid fid, \"typeOverbruggingsdeel\" as type, 'bridge'::text as class,
	  (ST_Dump(
		ST_Intersection(ST_SetSrid(a.wkb_geometry,28992), b.geom)
	  )).geom
	FROM bgt_light_import.\"Overbruggingsdeel\" a, bounds b
	WHERE 1 = 1
	AND \"typeOverbruggingsdeel\" = 'dek'
	AND ST_Intersects(ST_SetSrid(a.wkb_geometry,28992), b.geom)
	--AND ST_Intersects(ST_Centroid(ST_SetSrid(a.wkb_geometry,28992)), b.geom)
)
,polygons AS (
	SELECT * FROM footprints
	WHERE ST_GeometryType(geom) = 'ST_Polygon'
)
,rings AS (
	SELECT id, fid, type, class,geom as geom0, (ST_DumpRings(geom)).*
	FROM polygons
)
,edge_points AS (
	SELECT id, fid, type, class, geom0, path ring, (ST_Dumppoints(geom)).* 
	FROM rings
)
,edge_points_patch AS ( --get closest patch to every vertex
	SELECT a.id, a.fid, a.type, a.class, a.geom0, a.path, a.ring, a.geom,  --find closes patch to point
	PC_Explode(COALESCE(b.pa, --if not intersection, then get the closest one 
		(
		SELECT b.pa FROM ahn_pointcloud.ahn2objects b
		ORDER BY a.geom <#> Geometry(b.pa) 
		LIMIT 1
		)
	)) pt
	FROM edge_points a LEFT JOIN ahn_pointcloud.ahn2objects b
	ON ST_Intersects(
		a.geom,
		geometry(pa)
	)
),
emptyz AS ( 
	SELECT 
		a.id, a.fid, a.type, a.class, a.path, a.ring, a.geom,
		PC_Patch(pt) pa,
		PC_PatchMin(PC_Patch(pt), 'z') min,
		PC_PatchMax(PC_Patch(pt), 'z') max,
		PC_PatchAvg(PC_Patch(pt), 'z') avg
	FROM edge_points_patch a
	WHERE ST_Intersects(geom0, Geometry(pt))
	GROUP BY a.id, a.fid, a.type, a.class, a.path, a.ring, a.geom
)
,filter AS (
	SELECT
		a.id, a.fid, a.type, a.class, a.path, a.ring, a.geom,
		PC_Get(PC_Explode(PC_FilterBetween(pa, 'z',avg-1, avg+1)),'z') z
	FROM emptyz a
)
-- assign z-value for every boundary point
,filledz AS ( 
	SELECT id, fid, type, class, path, ring, ST_Translate(St_Force3D(geom), 0,0,avg(z)) geom
	FROM filter
	GROUP BY id, fid, type, class, path, ring, geom
	ORDER BY id, ring, path
)
,allrings AS (
	SELECT id, fid, type, class, ring, ST_AddPoint(ST_MakeLine(geom), First(geom)) geom
	FROM filledz
	GROUP BY id,fid, type, class, ring
)
,outerrings AS (
	SELECT *
	FROM allrings
	WHERE ring[1] = 0
)
,innerrings AS (
	SELECT id, fid, type, class, St_Accum(geom) arr
	FROM allrings
	WHERE ring[1] > 0
	GROUP BY id, fid, type, class
),
polygonsz AS (
	SELECT a.id, a.fid, a.type, a.class, COALESCE(ST_MakePolygon(a.geom, b.arr),ST_MakePolygon(a.geom)) geom 
	FROM outerrings a
	LEFT JOIN innerrings b ON a.id = b.id
)
,terrain_polygons AS (
    SELECT * FROM polygonsz
)
,patches AS (
	SELECT t.id, pa
	FROM ahn_pointcloud.ahn2objects, terrain_polygons t
	WHERE ST_Intersects(
		geom,
		geometry(pa)
	)
),
all_points AS ( -- get pts in every boundary
	SELECT t.id, geometry(PC_Explode(pa)) geom 
	FROM ahn_pointcloud.ahn2objects, terrain_polygons t
	WHERE ST_Intersects(
		geom,
		geometry(pa)
	)
),
innerpoints AS (
	SELECT a.id, a.geom
	FROM all_points a
	INNER JOIN terrain_polygons b
	ON a.id = b.id 
	AND ST_Intersects(a.geom, b.geom)
	AND Not ST_DWithin(a.geom, ST_ExteriorRing(b.geom),1)
	WHERE random() < (0.1 * $zoom)
	AND (b.class != 'road') 
)
,basepoints AS (
	--SELECT id, geom FROM innerpoints
	--UNION
	SELECT id,geom FROM polygonsz
	WHERE ST_IsValid(geom)
)
,triangles AS (
	SELECT 
		id,
		ST_MakePolygon(
			ST_ExteriorRing(
				(ST_Dump(ST_Triangulate2DZ(ST_Collect(a.geom)))).geom
			)
		)geom
	FROM basepoints a
	GROUP BY id
)
,assign_triags AS (
	SELECT 	a.*, b.type, b.class
	FROM triangles a
	INNER JOIN polygons b
	ON ST_Contains(b.geom, a.geom)
	,bounds c
	WHERE ST_Intersects(ST_Centroid(b.geom), c.geom)
	AND a.id = b.id
)

SELECT $south::text || $west::text || p.id, 'brug' as type,
	COALESCE(s.color, '0.66 0.37 0.13') color,
	ST_AsX3D(ST_Collect(p.geom),3) geom
	,p.type AS label
FROM assign_triags p
LEFT JOIN bgt.vw_style s ON (p.type = s.type) 
GROUP BY p.id, p.type, s.color
";


$result = pg_query($conn, $query);
if (!$result) {
  echo "An error occurred.\n";
  exit;
}

$res_string = "id;type;color;geom;label;\n";
while ($row = pg_fetch_row($result)) {
	$res_string = $res_string . implode(';',$row) . "\n";
}
ob_start("ob_gzhandler");
echo $res_string;
ob_end_flush();

?>
