<?php

$bagid = $_REQUEST['bagid'];

//header('Content-type: application/json');
$conn = pg_pconnect("host=192.168.24.15 dbname=research user=postgres");
if (!$conn) {
  echo "A connection error occurred.\n";
  exit;
}
$query = "
WITH 
bounds AS (
	SELECT geometrie_28992 geom
	FROM bagimproved_201405.pand
	WHERE identificatie = $bagid
), 
patches AS (
	SELECT a.* FROM ahn_pointcloud.ahn2objects a, bounds b
	WHERE ST_Intersects(Geometry(a.pa), b.geom)
	LIMIT 1000 --SAFETY
	
),
points AS (
	SELECT Geometry(PC_Explode(pa)) geom
	FROM patches
)
SELECT  ST_X(St_Transform(a.geom,28992)) x, ST_Y(St_Transform(a.geom,28992)) y, ST_Z(St_Transform(a.geom,28992)) z  
FROM points a, bounds b
WHERE ST_Intersects(a.geom, b.geom);";


$result = pg_query($conn, $query);
if (!$result) {
  echo "An error occurred.\n";
  exit;
}
$res_string = $bagid . "\n";
while ($row = pg_fetch_row($result)) {
	$res_string = $res_string . implode(';',$row) . "\n";
}
ob_start("ob_gzhandler");
echo $res_string;
ob_end_flush();
?>
