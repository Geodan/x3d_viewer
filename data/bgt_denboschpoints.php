<?php

$north = $_REQUEST['north'];
$south = $_REQUEST['south'];
$west  = $_REQUEST['west'];
$east  = $_REQUEST['east'];

$width = $east - $west;
$height = $north - $south;
$area = $width * $height;
$zoom = 500 / $area;
//$zoom  = $_REQUEST['zoom'] ?: 0.005;
header('Content-type: application/json');
$conn = pg_pconnect("host=titania dbname=research user=postgres password=postgres");
if (!$conn) {
  echo "A connection error occurred.\n";
  exit;
}
$query = "

WITH 
bounds AS (
	--SELECT ST_MakeEnvelope($west, $south, $east, $north, 28992) geom
	SELECT ST_MakeEnvelope(148949,411257, 148949 + 50, 411257 + 50, 28992) geom
),
pointcloud_unclassified AS(
	SELECT pa pa  
	FROM pc_denbosch.pointcloud, bounds 
	WHERE ST_Intersects(geom, Geometry(pa))
),
patches AS (
	SELECT Round(nextval('counter')/10) id, a.pa FROM pointcloud_unclassified a
	--LIMIT 1000 --SAFETY
),
grouper AS (
	SELECT id, PC_Union(pa) pa 
	FROM patches
	GROUP BY id 
),
points AS (
	SELECT id, PC_Explode(pa) pt FROM grouper
)
,points_reduced AS (
	SELECT * FROM points
	WHERE random() < 0.05 --reduce the number of points
)
,longstrings AS (
	SELECT id,
	array_to_string(Array_agg(
		Round(PC_Get(pt,'Red')/100000,2)::Text || ' ' ||
		Round(PC_Get(pt,'Green')/100000,2)::Text  || ' ' ||
		Round(PC_Get(pt,'Blue')/100000,2)::Text),' ') color,
	array_to_string(Array_agg(
		PC_Get(pt,'X')::Text || ' ' ||
		PC_Get(pt,'Y')::Text  || ' ' ||
		PC_Get(pt,'Z')::Text),' ') point
	FROM points_reduced
	GROUP BY id
)

SELECT id, 'denbosch' as type, '<PointSet><Color color=''' || color || '''/><Coordinate point='''|| point ||'''/></PointSet>' geom
FROM longstrings;
";


$result = pg_query($conn, $query);
if (!$result) {
  echo "An error occurred.\n";
  exit;
}
$res_string = "id;type;geom; \n";
while ($row = pg_fetch_row($result)) {
	$res_string = $res_string . implode(';',$row) . "\n";
}
ob_start("ob_gzhandler");
echo $res_string;
ob_end_flush();
?>
