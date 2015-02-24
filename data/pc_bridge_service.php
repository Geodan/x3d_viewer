<?php

$north = $_REQUEST['north'];
$south = $_REQUEST['south'];
$west  = $_REQUEST['west'];
$east  = $_REQUEST['east'];

header('Content-type: application/json');
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
bridges AS (
	SELECT 
	  ST_Union(ST_Intersection(wkb_geometry, geom)) As geom,
  	  fysiekvoorkomen
	FROM brt_201402.wegdeel_vlak a, bounds b
	WHERE ST_Intersects(a.wkb_geometry, b.geom)
	AND typeinfrastructuurwegdeel != 'overig verkeersgebied'
	AND fysiekvoorkomen Is Not Null
	GROUP BY brugnaam, fysiekvoorkomen
),
patches AS (
	SELECT a.* FROM ahn_pointcloud.ahn2objects a, bridges b
	WHERE ST_Intersects(geometry(a.pa), b.geom)
	LIMIT 1000 --SAFETY
),
points AS (
	SELECT PC_Explode(pa) pt
	FROM patches
)
SELECT 0 as id, 'bridge' as type, '1 1 1' as color, ST_AsX3D(ST_Collect(geometry(pt))) geom
--SELECT PC_Get(pt,'x') x, PC_Get(pt,'y') y, PC_Get(pt,'z') z 
FROM points a, bridges b
--WHERE ST_Intersects(geometry(a.pt), b.geom)
;";

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
