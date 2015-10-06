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
	SELECT ST_MakeEnvelope($west, $south, $east, $north, 28992) geom
),
pointcloud_unclassified AS(
	SELECT PC_FilterEquals(pa,'classification',2) pa  
	FROM ahn3_pointcloud.vw_ahn3, bounds 
	WHERE ST_DWithin(geom, Geometry(pa),10) --patches should be INSIDE bounds
),
patches AS (
	SELECT a.pa FROM pointcloud_unclassified a
	LIMIT 1000 --SAFETY
),
points AS (
	SELECT Geometry(PC_Explode(pa)) geom
	FROM patches
),
points_filtered AS (
	SELECT * FROM points 
	WHERE random() < $zoom --reduce the number of points
)
SELECT nextval('counter') as id, 'tree' as type, '0 ' || random() * 0.1 ||' 0' as color, ST_AsX3D(ST_Collect(a.geom)) geom
FROM points_filtered a
";


$result = pg_query($conn, $query);
if (!$result) {
  echo "An error occurred.\n";
  exit;
}
$res_string = "id;type;color;geom; \n";
while ($row = pg_fetch_row($result)) {
	$res_string = $res_string . implode(';',$row) . "\n";
}
ob_start("ob_gzhandler");
echo $res_string;
ob_end_flush();
?>
