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
	$south::text || $west::text || 'tree' || ogc_fid id,
	a.geom As geom
	FROM boomregister.bomen a, bounds b
	WHERE 1 = 1 
	AND ST_Intersects(a.geom, b.geom)
	AND ST_Intersects(ST_Centroid(a.geom), b.geom)
	AND ST_GeometryType(a.geom) = 'ST_Polygon'
),
patches AS (
	SELECT b.id, a.pa FROM ahn2 a, footprint b
	WHERE ST_Intersects(a.pa::geometry, b.geom)
	AND ST_GeometryType(b.geom) = 'ST_Polygon'
	LIMIT 1000 --SAFETY
),
points AS (
	SELECT id, PC_Explode(pa)::geometry geom
	FROM patches
),
points_filtered AS (
	SELECT * FROM points 
	WHERE random() < $zoom --reduce the number of points
)
SELECT b.id, 'tree' as type, '0 ' || random() * 0.1 ||' 0' as color, ST_AsX3D(ST_Collect(a.geom)) geom
FROM points_filtered a, footprint b
WHERE ST_Intersects(a.geom, b.geom)
GROUP BY b.id;
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
