<?php

$north = $_REQUEST['north'];
$south = $_REQUEST['south'];
$west  = $_REQUEST['west'];
$east  = $_REQUEST['east'];
$zoom  = $_REQUEST['zoom'] ?: 0.005;

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
	SELECT ST_MakeEnvelope($west, $south, $east, $north, 28992) geom
),

points AS (
	SELECT a.ogc_fid id, a.wkb_geometry geom 
	FROM bgt_import.\"Paal\" a, bounds b 
	WHERE \"plus-type\" = 'lichtmast'
	AND ST_Intersects(a.wkb_geometry, b.geom)

),
pointsz As (
	SELECT a.id, ST_Translate(ST_Force3D(a.geom),0,0,COALESCE(PC_PatchAvg(pa, 'z'),-99)+5) geom
	FROM points a
	LEFT JOIN ahn_pointcloud.ahn2terrain b ON ST_Intersects(
		geometry(pa), 
		a.geom
	)
)
SELECT id, 'light' as type, ST_X(geom) x, ST_Y(geom) y, ST_Z(geom) z FROM pointsz;";
//'PointLight').attr('location','188250 429009 40').attr('radius','50').attr('intensity',0.1);
$result = pg_query($conn, $query);
if (!$result) {
  echo "An error occurred.\n";
  exit;
}

$res_string = "id;type;x;y;z;\n";
while ($row = pg_fetch_row($result)) {
	$res_string = $res_string . implode(';',$row) . "\n";
}
ob_start("ob_gzhandler");
echo $res_string;
ob_end_flush();

?>
