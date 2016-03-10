<?php

$north = $_REQUEST['north'];
$south = $_REQUEST['south'];
$west  = $_REQUEST['west'];
$east  = $_REQUEST['east'];

$width = $east - $west;
$height = $north - $south;
$area = $width * $height;

$diagonal = sqrt(pow($width,2) + pow($height,2));
$zoom = 100 / $diagonal;
//$zoom  = $_REQUEST['zoom'] ?: 0.005;

$segmentlength = $width / 10;

header('Content-type: application/json');
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
pointcloud_water AS (
	SELECT PC_FilterEquals(pa,'classification',9) pa 
	FROM ahn3_pointcloud.vw_ahn3, bounds 
	WHERE ST_Intersects(geom, Geometry(pa)) --patches should be INSIDE bounds
),
terrain AS (
	SELECT nextval('counter') id, ogc_fid fid, type as type, class,
	  (ST_Dump(
		ST_Intersection(a.geom, b.geom)
	  )).geom
	FROM bgt.vw_polygons a, bounds b
	WHERE ST_Intersects(a.geom, b.geom)
	AND ST_IsValid(a.geom)
	and class = 'water'
)
,polygons AS (
	SELECT * FROM terrain
	WHERE ST_GeometryType(geom) = 'ST_Polygon'
)
,polygonsz AS ( 
	SELECT a.id, a.fid, a.type, a.class, 
	--ST_Translate(ST_Force3D(a.geom), 0,0,COALESCE(min(PC_PatchMin(b.pa,'z')),0)) geom
	ST_Translate(ST_Force3D(a.geom), 0,0,0) geom --fixed level
	FROM polygons a 
	LEFT JOIN pointcloud_water b
	ON ST_Intersects(
		a.geom,
		geometry(pa)
	)
	GROUP BY a.id, a.fid, a.type, a.class, a.geom
)
,basepoints AS (
	SELECT id,geom FROM polygonsz
	WHERE ST_IsValid(geom)
)
,triangles AS (
	SELECT 
		id,
		ST_MakePolygon(
			ST_ExteriorRing(
				(ST_Dump(ST_Triangulate2DZ(ST_Collect(a.geom)))).geom
			)
		)geom
	FROM basepoints a
	GROUP BY id
)
,assign_triags AS (
	SELECT 	a.*, b.type, b.class
	FROM triangles a
	INNER JOIN polygons b
	ON ST_Contains(b.geom, a.geom)
	,bounds c
	WHERE ST_Intersects(ST_Centroid(b.geom), c.geom)
	AND a.id = b.id
)

SELECT $south::text || $west::text || p.id, p.type as type,
COALESCE(s.color, 'red') color,
ST_AsX3D(ST_Collect(p.geom),3),p.type AS label
FROM assign_triags p
LEFT JOIN bgt.style s ON (p.type = s.type) 
GROUP BY p.id, p.type, s.color
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
