<?php

$north = $_REQUEST['north'];
$south = $_REQUEST['south'];
$west  = $_REQUEST['west'];
$east  = $_REQUEST['east'];


$width = $east - $west;
$height = $north - $south;
$area = $width * $height;
$zoom = 10 / $area;

$segmentlength = 5; //round($area / (25*25)); 

header('Content-type: application/json');
//$conn = pg_pconnect("host=192.168.26.76 dbname=research user=postgres password=postgres");
$conn = pg_pconnect("host=titania dbname=research user=postgres password=postgres");
if (!$conn) {
  echo "A connection error occurred.\n";
  exit;
}
$query = "
WITH 
bounds AS (
	SELECT ST_Segmentize(ST_MakeEnvelope($west, $south, $east, $north, 28992),$segmentlength) geom
),
bridges AS (
	SELECT $west::text || $south::text || 'r'||fid id, 'bridge'::text as type, tdncode,
	  ST_Intersection(wkb_geometry, geom) As geom
	FROM brt_201402.wegdeel_vlak a, bounds b
	WHERE ST_Intersects(a.wkb_geometry, b.geom)
	AND fysiekvoorkomen Is Not Null --only bridges and tunnels
),
roads AS (
	SELECT $west::text || $south::text || 'r'||fid id, 'wegvlak'::text as type, tdncode,
	  ST_Intersection(wkb_geometry, geom) As geom
	FROM brt_201402.wegdeel_vlak a, bounds b
	WHERE ST_Intersects(a.wkb_geometry, b.geom)
	AND fysiekvoorkomen Is Null --remove bridges and tunnels
),
ramps AS (
	SELECT a.id, 'bridge'::text as type, a.tdncode, a.geom
	FROM roads a, bridges b
	WHERE ST_Touches(a.geom, b.geom) 
	AND a.tdncode = b.tdncode
),
polygons1 AS (
	SELECT * FROM bridges
	UNION
	SELECT * FROM ramps
),
polygons AS (
    SELECT first(id) id, type, ST_Union(geom) geom
    FROM polygons1
    GROUP BY type, tdncode 
),
points AS ( -- get pts from patches that intersect polygon
	SELECT t.id, t.type, PC_Explode(pa) pt
	FROM ahn_pointcloud.ahn2terrain, polygons t
	WHERE ST_Intersects(
		geom,
		geometry(pa)
	)
),
--Get PC points within polygons 
points_in_polygon AS ( 
	SELECT t.id, t.type, t.geom, PC_Patch(p.pt) pa 
	FROM points p
	JOIN polygons t ON (p.id = t.id AND ST_Intersects(
		ST_Buffer(t.geom,-0.5),
		geometry(p.pt)
	))
	GROUP BY t.id, t.type, t.geom
),
height AS (
    SELECT t.id, t.type, ST_Force3D(ST_ForceLHR(t.geom)) geom, PC_PatchMax(pa, 'Z') height
    FROM points_in_polygon t
),
translated AS (
    SELECT id, type, ST_Translate(geom, 0,0,height) geom
    FROM height
)
SELECT $south::text || $west::text || id, 'bridge' as type, 
	'orange'  as color, 
	ST_AsX3D(geom,3) geom, type As label
FROM translated
;
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
