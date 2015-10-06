<?php

$north = $_REQUEST['north'];
$south = $_REQUEST['south'];
$west  = $_REQUEST['west'];
$east  = $_REQUEST['east'];
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
water AS (
	SELECT fid id,
	  ST_Intersection(wkb_geometry, geom) As geom
	FROM brt_201402.waterdeel_vlak a, bounds b
	WHERE ST_Intersects(a.wkb_geometry, b.geom)
),
triags AS (
	SELECT nextval('counter') id, (ST_Dump(
		   ST_Tesselate(
			geom
		   )
		)).geom, geom as orig_geom
	FROM water
),

points As (
	SELECT 
		id, (ST_DumpPoints(geom)).* 
	FROM water
),

pointsheight As (
	SELECT 
		 
		ST_Force3D(geom) geom,
		--ST_Translate(ST_Force3D(geom),0,0,COALESCE(PC_PatchMax(pa, 'z'),1)) geom,
		COALESCE(PC_PatchMin(pa, 'z'),1) as zval
		, path, points.id
	FROM points
	LEFT JOIN ahn2terrain b ON (ST_Intersects(geom, b.pa::geometry))
	ORDER BY id, path
),

polygons As (
	SELECT ST_Translate(ST_MakePolygon(ST_Reverse(ST_MakeLine(geom))),0,0,min(zval)) geom
	FROM pointsheight
	GROUP BY id
)
SELECT 'water' as type, 'blue' as color, ST_AsX3D(ST_Collect(polygons.geom))
FROM polygons
";

$result = pg_query($conn, $query);
if (!$result) {
  echo "An error occurred.\n";
  exit;
}
$res_string = "type;color;geom;\n";
while ($row = pg_fetch_row($result)) {
	$res_string = $res_string . implode(';',$row) . "\n";
}
ob_start("ob_gzhandler");
echo $res_string;
ob_end_flush();
?>
