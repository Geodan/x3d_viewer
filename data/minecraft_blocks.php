<?php

$north = $_REQUEST['north'];
$south = $_REQUEST['south'];
$west  = $_REQUEST['west'];
$east  = $_REQUEST['east'];

header('Content-type: application/json');
$conn = pg_pconnect("host=titania dbname=research user=postgres");
if (!$conn) {
  echo "A connection error occurred.\n";
  exit;
}
$query = "
/*
WITH 
bounds AS (
	--SELECT ST_Buffer(ST_Transform(ST_SetSrid(ST_MakePoint($lon, $lat),4326), 28992),200) geom
	SELECT ST_MakeEnvelope($west, $south, $east, $north, 28992) geom
), */
WITH grid AS (
	SELECT generate_series(0,50) AS val, 148949 x,411257 y
)
,height AS (
	SELECT generate_series(5,30) AS val
)
,boxes AS (
    SELECT 
	ST_MakeEnvelope(a.x + a.val, a.y + b.val,a.x + a.val + 1,a.y + b.val + 1,28992) geom,
	 h.val z
    FROM grid a, grid b, height h
)

,points AS (
    SELECT --PC_Explode(pa) pt
    PC_Explode(PC_FilterBetween(pa,'z',z,z+1)) pt, boxes.geom,z
    FROM pc_denbosch.pointcloud,boxes
    WHERE ST_Intersects(Geometry(pa),boxes.geom)
    --AND random() < 0.5
    --unnessesary? AND PC_PatchMin(pa,'z') < z+1 AND PC_PatchMax(pa,'z') > z
),polygons AS (
	SELECT
	nextval('counter') id,
	'blokje' as type,
	(Median(PC_Get(pt,'Red')/100.0))/1000.0 ||' '||
	(Median(PC_Get(pt,'Green')/100.0))/1000.0 ||' '||
	(Median(PC_Get(pt,'Blue')/100.0))/1000.0 AS color,
	ST_Extrude(ST_Translate(ST_Force3D(geom),0,0, z), 0,0,1) geom
	FROM points
	WHERE ST_Intersects(Geometry(pt),geom)
	AND PC_Get(pt,'z') > z AND PC_Get(pt,'z') < z+1
	GROUP BY geom,z
	HAVING count(pt) > 500
	AND NOT (
		stddev(PC_Get(pt,'x')) < 0.05 
		AND stddev(PC_Get(pt,'y')) < 0.05
	)
)
SELECT id, type, color, ST_AsX3D(p.geom) geom
FROM polygons p  
";

$result = pg_query($conn, $query);
if (!$result) {
  echo "An error occurred.\n";
  echo pg_last_error(); 
  exit;
}
$res_string = "id;type;color;geom;\n";
while ($row = pg_fetch_row($result)) {
	$res_string = $res_string . implode(';',$row) . "\n";
}
ob_start("ob_gzhandler");
echo $res_string;
ob_end_flush();
?>
