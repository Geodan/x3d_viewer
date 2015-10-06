<?php

$north = $_REQUEST['north'];
$south = $_REQUEST['south'];
$west  = $_REQUEST['west'];
$east  = $_REQUEST['east'];


$width = $east - $west;
$height = $north - $south;
$area = $width * $height;
$zoom = 50 / $area;

$segmentlength = round($area / (25*25)); 

header('Content-type: application/json');
//$conn = pg_pconnect("host=192.168.26.76 dbname=research user=postgres password=postgres");
$conn = pg_pconnect("host=titania dbname=research user=geodan password=Gehijm");
if (!$conn) {
  echo "A connection error occurred.\n";
  exit;
}
$query = "
WITH 
bounds AS (
	SELECT ST_Segmentize(ST_MakeEnvelope($west, $south, $east, $north, 28992),$segmentlength) geom
),
pieces AS (
		SELECT ST_Reverse(wkb_geometry) geom, ogc_fid id, 'water'::text type_landg 
		FROM top103d.brugwater a, bounds b
		WHERE ST_Intersects(a.wkb_geometry, b.geom)
		UNION
		SELECT ST_Reverse(wkb_geometry) geom, ogc_fid id, 'bridge'::text type_landg 
		FROM top103d.brugweg a, bounds b
		WHERE ST_Intersects(a.wkb_geometry, b.geom)
)

SELECT 
--$south::text || $west::text || 
id, 'terrain1' as type, 
	CASE
		WHEN type_landg = 'overig' THEN 'gray'
		WHEN type_landg = 'bebouwd gebied' THEN 'gray'
		WHEN type_landg = 'grasland' THEN 'green'
		WHEN type_landg = 'bos: loofbos' THEN '0 0.4 0'
		WHEN type_landg = 'basaltblokken, steenglooiing' THEN 'black'
		WHEN type_landg = 'wegvlak' THEN '0.4 0.4 0.4'
		WHEN type_landg = 'water' THEN '0 0 0.6'
		WHEN type_landg = 'zand' THEN '0.8 0.8 0.3'
		WHEN type_landg = 'akkerland' THEN '0.8 0.7 0.3'
		ELSE 'gray'
	END as color, 
	ST_AsX3D(geom,3) geom, type_landg As label
FROM pieces;
";

$result = pg_query($conn, $query);
if (!$result) {
  echo "An error occurred.\n";
  exit;
}
$res_string = "id;type;color;geom;label;\n";
while ($row = pg_fetch_row($result)) {
	$res_string = $res_string . implode(';',$row) . "\n";
}
ob_start("ob_gzhandler");
echo $res_string;
ob_end_flush();
?>
