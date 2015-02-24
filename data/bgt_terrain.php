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
terrain AS (
	SELECT nextval('counter') id, ogc_fid fid, type as type, class,
	  (ST_Dump(
		ST_Intersection(a.geom, b.geom)
	  )).geom
	FROM bgt.polygons a, bounds b
	WHERE ST_Intersects(a.geom, b.geom)
	AND ST_IsValid(a.geom)
	and class != 'water'
	and type != 'kademuur'
)
,polygons AS (
	SELECT * FROM terrain
	WHERE ST_GeometryType(geom) = 'ST_Polygon'
)
,rings AS (
	SELECT id, fid, type, class,(ST_DumpRings(geom)).*
	FROM polygons
)
,edge_points AS (
	SELECT id, fid, type, class, path ring, (ST_Dumppoints(geom)).* 
	FROM rings
	WHERE type != 'water'
)

,edge_points_patch AS ( --get closest patch to every vertex
	SELECT a.id, a.fid, a.type, a.class, a.path, a.ring, a.geom,  --find closes patch to point
	COALESCE(b.pa, 
		(
		SELECT b.pa FROM ahn_pointcloud.ahn2terrain b
		ORDER BY a.geom <#> Geometry(b.pa) 
		LIMIT 1
		)
	) pa
	FROM edge_points a LEFT JOIN ahn_pointcloud.ahn2terrain b
	ON ST_Intersects(
		a.geom,
		geometry(pa)
	)
),
emptyz AS ( --find closest pt for every boundary point
	SELECT a.*, ( --find closest pc.pt to point
		SELECT b.pt FROM (SELECT PC_Explode(a.pa) pt ) b
		ORDER BY a.geom <#> Geometry(b.pt)
		LIMIT 1
	) pt
	FROM edge_points_patch a
)
-- assign z-value for every boundary point
,filledz AS ( 
	SELECT id, fid, type, class, path, ring, ST_Translate(St_Force3D(geom), 0,0,PC_Get(first(pt),'z')) geom
	FROM emptyz
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
,breaklines AS (
	SELECT a.* FROM brt_201402.hoogteverschilhz_lijn a, bounds b
	WHERE ST_Intersects(a.wkb_geometry, b.geom)
	UNION 
	SELECT a.* FROM brt_201402.hoogteverschillz_lijn a, bounds b
	WHERE ST_Intersects(a.wkb_geometry, b.geom)
),
patches AS (
	SELECT t.id, pa
	FROM ahn_pointcloud.ahn2terrain, terrain_polygons t
	WHERE ST_Intersects(
		geom,
		geometry(pa)
	)
),
all_points AS ( -- get pts in every boundary
	SELECT t.id, geometry(PC_Explode(pa)) geom 
	FROM ahn_pointcloud.ahn2terrain, terrain_polygons t
	WHERE ST_Intersects(
		geom,
		geometry(pa)
	)
),
--3 sets of points from the pointcloud with different densities
--1) Points close to breaklines
breakline_points AS (
	SELECT a.geom
	FROM all_points a
	INNER JOIN (SELECT ST_Collect(wkb_geometry) geom FROM breaklines) b
	ON ST_DWithin(a.geom, b.geom,0.5)
	INNER JOIN terrain_polygons c
	ON ST_Intersects(a.geom, c.geom)
	AND Not ST_DWithin(a.geom, ST_ExteriorRing(c.geom),1)
	WHERE random() < (1 * $zoom)
),
--2) points close to and inside border
border_points AS (
	SELECT a.geom
	FROM all_points a
	INNER JOIN terrain_polygons b
	ON ST_Intersects(a.geom, b.geom)
	AND ST_DWithin(a.geom, ST_ExteriorRing(b.geom),3)
	AND Not ST_DWithin(a.geom, ST_ExteriorRing(b.geom),1)
	WHERE random() < (1 * $zoom)
),
--3) other points (more distant from breakline or border)
other_points AS (
	SELECT a.id, a.geom
	FROM all_points a
	INNER JOIN terrain_polygons b
	ON a.id = b.id 
	AND ST_Intersects(a.geom, b.geom)
	AND Not ST_DWithin(a.geom, ST_ExteriorRing(b.geom),1)
	WHERE random() < (0.1 * $zoom)
	AND (b.class != 'road') 
)

--Put all points together
,innerpoints AS (
	--SELECT geom FROM border_points
	--UNION
	--SELECT geom FROM breakline_points
	--UNION
	SELECT id, geom FROM other_points
)
,basepoints AS (
	SELECT id, geom FROM innerpoints
	UNION
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

SELECT $south::text || $west::text || p.id, 'terrain2' as type,
	COALESCE(s.color, 'red') color,
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
