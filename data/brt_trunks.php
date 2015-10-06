<?php

$north = $_REQUEST['north'];
$south = $_REQUEST['south'];
$west  = $_REQUEST['west'];
$east  = $_REQUEST['east'];
$zoom  = $_REQUEST['zoom'] ?: 0.005;
//header('Content-type: application/json');
$conn = pg_pconnect("host=titania dbname=research user=postgres password=postgres");
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
footprints AS (
	SELECT 
	ogc_fid id,
	a.geom As geom
	FROM boomregister.bomen a, bounds b
	WHERE 1 = 1 
	AND ST_Intersects(a.geom, b.geom)
	AND ST_Intersects(ST_Centroid(a.geom), b.geom)
	AND ST_GeometryType(a.geom) = 'ST_Polygon'
),
stats_fast AS (
	SELECT 
		PC_PatchAvg(PC_Union(b.pa),'z') max,
		PC_PatchMin(PC_Union(c.pa),'z') min,
		a.id,
		a.geom footprint
	FROM footprints a
	LEFT JOIN ahn2 b ON (ST_Intersects(a.geom, geometry(b.pa)))
	LEFT JOIN ahn2terrain c ON (ST_Intersects(a.geom, geometry(c.pa)))
	GROUP BY a.id, a.geom
),
polygons AS (
	SELECT 
	footprint geom
	--ST_Extrude(ST_Translate(ST_Force3D(ST_Buffer(ST_Centroid(footprint),1)),0,0, min), 0,0,max-min) geom 
	-- ST_Translate(ST_Force3D(ST_Centroid(footprint)),0,0,1) geom FROM stats_fast
	-- 
	--ST_MakeLine(
	--	ST_Translate(ST_Force3D(ST_Centroid(footprint)),0,0,min), 
	--	ST_Translate(ST_Force3D(ST_Centroid(footprint)),0,0,max)
	--) geom 
	FROM stats_fast
)
SELECT 'polygon' as type, 'brown' as color, ST_AsText(polygons.geom)
FROM polygons
";

$result = pg_query($conn, $query);
if (!$result) {
  echo "An error occurred.\n";
  exit;
}
$res_string = "type;color;geom; \n";
while ($row = pg_fetch_row($result)) {
	$res_string = $res_string . implode(';',$row) . "\n";
}
ob_start("ob_gzhandler");
echo $res_string;
ob_end_flush();
?>
