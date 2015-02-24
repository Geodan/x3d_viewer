<?php

$north = $_REQUEST['north'];
$south = $_REQUEST['south'];
$west  = $_REQUEST['west'];
$east  = $_REQUEST['east'];

//header('Content-type: application/json');
$conn = pg_pconnect("host=192.168.26.76 dbname=research user=postgres password=postgres");
if (!$conn) {
  echo "A connection error occurred.\n";
  exit;
}
$query = "
WITH 
bounds AS (
	--SELECT ST_Buffer(ST_Transform(ST_SetSrid(ST_MakePoint($lon, $lat),4326), 28992),200) geom
	SELECT ST_MakeEnvelope($west, $south, $east, $north, 28992) geom
), 
footprint AS (
	SELECT 
	wkb_geometry As geom
	FROM brt_201402.gebouw_vlak a, bounds b
	WHERE 1 = 1 
	AND naamnl Is Not Null
	
	AND ST_Intersects(wkb_geometry, geom)
),
patches AS (
	SELECT a.* FROM ahn2 a, footprint b
	WHERE ST_Intersects(a.pa::geometry, b.geom)
	LIMIT 1000 --SAFETY
	
),
points AS (
	SELECT PC_Explode(pa)::geometry geom
	FROM patches
)
SELECT 'points' as type, ST_AsX3D(ST_Collect(a.geom))  
FROM points a, footprint b
WHERE ST_Intersects(a.geom, b.geom);";


$result = pg_query($conn, $query);
if (!$result) {
  echo "An error occurred.\n";
  exit;
}
$res_string = "type;geom; \n";
while ($row = pg_fetch_row($result)) {
	$res_string = $res_string . implode(';',$row) . "\n";
}
ob_start("ob_gzhandler");
echo $res_string;
ob_end_flush();
?>
