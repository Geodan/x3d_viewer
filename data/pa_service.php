<?php

$north = $_REQUEST['north'];
$south = $_REQUEST['south'];
$west  = $_REQUEST['west'];
$east  = $_REQUEST['east'];

//header('Content-type: application/text');
//$conn = pg_pconnect("host=192.168.26.76 dbname=research user=postgres password=postgres");
$conn = pg_pconnect("host=192.168.24.15 dbname=research user=postgres password=postgres");
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
patches AS (
	SELECT a.* FROM ahn_pointcloud.ahn2objects a, bounds b
	WHERE ST_Intersects(Geometry(a.pa), b.geom)
	LIMIT 1000 --SAFETY
),
points AS (
	SELECT Geometry(PC_Explode(pa)) pt
	FROM patches
)
SELECT ST_AsText(ST_Union(Geometry(pt))) wkb
FROM points a
;";

$result = pg_query($conn, $query);
if (!$result) {
  echo "An error occurred.\n";
  exit;
}
//echo "x;y;z; \n";

$res_string = "";
while ($row = pg_fetch_row($result)) {
	$res_string = $res_string . implode(';',$row) . "\n";
}

//$compressed = gz_encode($res_string);
//echo $compressed;
ob_start("ob_gzhandler");
echo $res_string;
ob_end_flush();


?>
