<?php

$north = $_REQUEST['north'];
$south = $_REQUEST['south'];
$west  = $_REQUEST['west'];
$east  = $_REQUEST['east'];

//header('Content-type: application/json');
//$conn = pg_pconnect("host=192.168.26.76 dbname=research user=postgres password=postgres");
$conn = pg_pconnect("host=metis dbname=research user=postgres password=postgres");
if (!$conn) {
  echo "A connection error occurred.\n";
  exit;
}
$query = "
WITH 
bounds AS (
	SELECT ST_MakeEnvelope($west, $south, $east, $north, 28992) geom
	
), 
patches AS (
	SELECT a.* FROM rws_pointcloud.zfs a, bounds b
	WHERE ST_Intersects(Geometry(a.pa), b.geom)
	AND x_min < $east::double precision
	AND y_min < $north::double precision
	AND x_max > $west::double precision
	AND y_max > $south::double precision
),
points AS (
	SELECT PC_Explode(pa) pt
	FROM patches
)
SELECT PC_Get(pt,'x') x, PC_Get(pt,'y') y, PC_Get(pt,'z') z,
round(PC_Get(pt,'Red')/65535 * 255) x, 
round(PC_Get(pt,'Green')/65535 * 255) y, 
round(PC_Get(pt,'Blue')/65535 * 255) z 
FROM points a
WHERE random() < 0.01
;";

$result = pg_query($conn, $query);
if (!$result) {
  echo "An error occurred.\n";
  exit;
}
$res_string = "x;y;z;r;g;b \n";
while ($row = pg_fetch_row($result)) {
	$res_string = $res_string . implode(';',$row) . "\n";
}
ob_start("ob_gzhandler");
echo $res_string;
ob_end_flush();
?>
