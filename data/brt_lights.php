<?php

$north = $_REQUEST['north'];
$south = $_REQUEST['south'];
$west  = $_REQUEST['west'];
$east  = $_REQUEST['east'];
$zoom  = $_REQUEST['zoom'] ?: 0.005;

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
--ROADS
roads AS (
	SELECT 
	  
	ST_Union(
	  ST_SnapToGrid(
		ST_Intersection(wkb_geometry, geom),
		0.1,0.1)) As geom
  	  
	FROM brt_201402.wegdeel_vlak a, bounds b
	WHERE ST_Intersects(a.wkb_geometry, b.geom)
	AND typeinfrastructuurwegdeel != 'overig verkeersgebied'
	AND fysiekvoorkomen Is Null
),
points AS (
	SELECT (ST_Dumppoints(ST_Segmentize(geom,100))).geom geom FROM roads
),
pointsz As (
	SELECT ST_Translate(ST_Force3D(a.geom),0,0,COALESCE(PC_PatchAvg(pa, 'z'),-99)) geom
	FROM points a
	LEFT JOIN ahn2terrain b ON ST_Intersects(
		geometry(pa), 
		geom
	)
)
SELECT 'light' as type, ST_X(geom) x, ST_Y(geom) y, ST_Z(geom) z FROM pointsz;";

$result = pg_query($conn, $query);
if (!$result) {
  echo "An error occurred.\n";
  exit;
}

$res_string = "type;x;y;z;\n";
while ($row = pg_fetch_row($result)) {
	$res_string = $res_string . implode(';',$row) . "\n";
}
ob_start("ob_gzhandler");
echo $res_string;
ob_end_flush();

?>
