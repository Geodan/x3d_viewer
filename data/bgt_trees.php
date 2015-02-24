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
footprint AS (
	SELECT 
	$south::text || $west::text || 'tree' || ogc_fid id,
	a.wkb_geometry As geom
	FROM bgt.vegetatieobject a, bounds b
	WHERE 1 = 1 
	AND ST_Intersects(a.wkb_geometry, b.geom)
),
buildings AS (
    SELECT St_Union(wkb_geometry) geom 
    FROM bgt.pand a, bounds b
    WHERE St_Intersects(a.wkb_geometry, b.geom)
    AND ST_IsValid(a.wkb_geometry)
    AND ST_GeometryType(a.wkb_geometry) = 'ST_MultiPolygon'
),
patches AS (
	SELECT b.id, a.pa FROM ahn_pointcloud.ahn2objects a, footprint b
	WHERE ST_DWithin(geometry(a.pa), b.geom,20)    
	LIMIT 1000 --SAFETY
),
points AS (
	SELECT id, Geometry(PC_Explode(pa)) geom
	FROM patches
),
points_filtered AS (
	SELECT * FROM points 
	--WHERE random() < $zoom --reduce the number of points
)
SELECT b.id, 'tree' as type, '0 ' || random() * 0.1 ||' 0' as color, ST_AsX3D(ST_Collect(a.geom)) geom
FROM points_filtered a, footprint b, buildings c
WHERE ST_DWithin(a.geom, b.geom,10)
AND Not ST_DWithin(ST_Force2D(a.geom), c.geom,1)
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
