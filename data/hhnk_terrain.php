<?php

$north = $_REQUEST['north'];
$south = $_REQUEST['south'];
$west  = $_REQUEST['west'];
$east  = $_REQUEST['east'];

$width = $east - $west;
$height = $north - $south;
$area = $width * $height;

$diagonal = sqrt(pow($width,2) + pow($height,2));
$zoom = 10 / $diagonal;
//$zoom  = $_REQUEST['zoom'] ?: 0.005;

$segmentlength = $width / 10;

header('Content-type: application/json');
$conn = pg_pconnect("host=192.168.24.15 dbname=research user=postgres password=postgres");
if (!$conn) {
  echo "A connection error occurred.\n";
  exit;
}
$query = "
WITH RECURSIVE
bounds AS (
	SELECT ST_Segmentize(ST_MakeEnvelope($west, $south, $east, $north, 28992),$segmentlength) geom
),
terrain AS (
	SELECT nextval('counter') id, id as fid, \"CODE_BETEKENIS\" as type,
	  (ST_Dump(
		ST_Intersection(a.geom, b.geom)
	  )).geom
	FROM hhnk_topo.polygons a, bounds b
	WHERE ST_Intersects(a.geom, b.geom)
	AND \"CODE\" Not Like 'DWV' --Watervlak
	AND \"CODE\" Not Like 'W%' --Weg
)
,roads AS (
	SELECT nextval('counter') id, id as fid, 'wegvlak'::text as type,
	(ST_Dump(
	  ST_Intersection(a.geom, b.geom)
	)).geom
	FROM hhnk_topo.polygons a, bounds b
	WHERE ST_Intersects(a.geom, b.geom)
	AND \"CODE\" Like 'W%' --Weg
)
,polygons AS (
	SELECT * FROM terrain
	WHERE ST_GeometryType(geom) = 'ST_Polygon'
	UNION
	SELECT * FROM roads
	WHERE ST_GeometryType(geom) = 'ST_Polygon'
)
,edge_points AS (
	SELECT id, fid, type, (ST_Dumppoints(geom)).*
	FROM polygons
)
,edge_points_patch AS ( --get closest patch to every vertex
	SELECT a.id, a.fid, type, a.path, a.geom,  --find closes patch to point
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
)
,emptyz AS ( --find closest pt for every boundary point
	SELECT a.*, ( --find closest pc.pt to point
		SELECT b.pt FROM (SELECT PC_Explode(a.pa) pt ) b
		ORDER BY a.geom <#> Geometry(b.pt)
		LIMIT 1
	) pt
	FROM edge_points_patch a
)
-- assign z-value for every boundary point
,filledz AS ( 
	SELECT id, fid, type, path, ST_Translate(St_Force3D(geom), 0,0,PC_Get(first(pt),'z')) geom
	FROM emptyz
	GROUP BY id, fid, type, path, geom
	ORDER BY id, path
)
,polygonsz AS (
	SELECT id, fid, type, ST_MakePolygon(ST_AddPoint(ST_MakeLine(geom), First(geom))) geom
	FROM filledz
	GROUP BY id,fid, type
)
,terrain_polygons AS (
    SELECT * FROM polygonsz
	WHERE type != 'wegvlak'
)
,breaklines AS (
	SELECT a.id, 
	 (ST_Dump(
		ST_Intersection(a.geom, b.geom)
	  )).geom 
	FROM hhnk_topo.kenmproflijnen a, bounds b
	WHERE ST_Intersects(a.geom, b.geom)
)
,breaklines_vertices AS (
	SELECT id, (ST_DumpPoints(geom)).* geom
	FROM breaklines
)
,breaklines_vertices_patch AS ( --get closest patch to every vertex
	SELECT a.id, a.path, a.geom,  --find closes patch to point
	COALESCE(b.pa, 
		(
		SELECT b.pa FROM ahn_pointcloud.ahn2terrain b
		ORDER BY a.geom <#> Geometry(b.pa) 
		LIMIT 1
		)
	) pa
	FROM breaklines_vertices a LEFT JOIN ahn_pointcloud.ahn2terrain b
	ON ST_Intersects(
		a.geom,
		geometry(pa)
	)
),
breaklines_emptyz AS ( --find closest pt for every boundary point
	SELECT a.*, ( --find closest pc.pt to point
		SELECT b.pt FROM (SELECT PC_Explode(a.pa) pt ) b
		ORDER BY a.geom <#> Geometry(b.pt)
		LIMIT 1
	) pt
	FROM breaklines_vertices_patch a
)
-- assign z-value for every boundary point
,breaklines_filledz AS ( 
	SELECT id, path, ST_Translate(St_Force3D(geom), 0,0,PC_Get(first(pt),'z')) geom
	FROM breaklines_emptyz
	GROUP BY id, path, geom
	ORDER BY id, path
)
,breaklinesz AS (
	SELECT id, ST_MakeLine(geom) geom
	FROM breaklines_filledz
	GROUP BY id
)
,patches AS (
	SELECT t.id, t.type, pa
	--PC_PatchMax(pa, 'z') - PC_PatchMin(pa,'z') as delta, 
	--ST_Intersects(ST_ExteriorRing(geom), Geometry(pa)) intersects
	FROM ahn_pointcloud.ahn2terrain, terrain_polygons t
	WHERE ST_Intersects(
		geom,
		geometry(pa)
	)
)
,all_points AS ( -- get pts in every boundary
	SELECT geometry(PC_Explode(pa)) geom 
	FROM ahn_pointcloud.ahn2terrain, terrain_polygons t
	WHERE ST_Intersects(
		geom,
		geometry(pa)
	)
)
,innerpoints AS (
	SELECT b.id, b.fid, a.geom
	FROM all_points a
	INNER JOIN terrain_polygons b
	ON ST_Intersects(a.geom, b.geom)
	AND Not ST_DWithin(a.geom, ST_ExteriorRing(b.geom),3)
	WHERE random() < (0.1 * $zoom)
)
,basepoints AS (
	SELECT id, geom FROM innerpoints
	UNION
	SELECT id, ST_ExteriorRing(geom) geom FROM polygonsz
	WHERE ST_IsValid(geom)
	UNION
	SELECT id, geom FROM breaklinesz
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
	SELECT 	a.*, b.type
	FROM triangles a
	INNER JOIN polygonsz b
	ON ST_Contains(b.geom, a.geom)
	,bounds c
	WHERE ST_Intersects(ST_Centroid(b.geom), c.geom)
	AND a.id = b.id
)

SELECT $south::text || $west::text || id, 'terrain2' as type,
CASE
		WHEN type = 'overig' THEN 'gray'
		WHEN type = 'Bebouwing' THEN 'gray'
		WHEN type = 'Grasvlak' THEN 'green'
		WHEN type = 'Boomgroep / struikgewas' THEN '0 0.4 0'
		WHEN type = 'Breuksteenvlak' THEN 'black'
		WHEN type = 'wegvlak' THEN '0.4 0.4 0.4'
		WHEN type = 'Erfvlak' THEN '0.8 0.8 0.3'
		WHEN type = 'Bouwland' THEN '0.8 0.7 0.3'
		WHEN type = 'Steigerwerk / aanlegsteiger' THEN 'black'
		WHEN type = 'Beton(plaat)vlak' THEN 'gray'
		WHEN type = 'Open verharding (klinkers, tegels)' THEN 'gray'
		WHEN type = 'Half verhard, onverhard' THEN 'gray'
		ELSE 'brown'
	END as color, 
ST_AsX3D(ST_Collect(p.geom),3),type AS label
FROM assign_triags p
GROUP BY p.id, p.type
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
