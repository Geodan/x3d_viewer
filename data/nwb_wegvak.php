<?php

$north = $_REQUEST['north'];
$south = $_REQUEST['south'];
$west  = $_REQUEST['west'];
$east  = $_REQUEST['east'];

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
)
,wegvakken_dump AS (
	SELECT ogc_fid, ST_DumpPoints(ST_Transform(a.wkb_geometry, 28992)) dump
	FROM nwb.wegvakken a,
	bounds b WHERE ST_Intersects(ST_Transform(a.wkb_geometry, 28992), b.geom)
)
,wegvakken_points AS (
	SELECT ogc_fid, (a.dump).geom, (a.dump).path
	FROM wegvakken_dump a
)
,wegvakken_points_pa AS (
	SELECT ogc_fid, path, geom, pa
	FROM wegvakken_points a,
	ahn_pointcloud.ahn2terrain b
	WHERE ST_Intersects(a.geom, geometry(b.pa))

)
,wegvakken_points_pt AS (
	SELECT a.*, ( --find closest pc.pt to point
		SELECT b.pt FROM (SELECT PC_Explode(a.pa) pt ) b
		ORDER BY a.geom <#> geometry(b.pt)
		LIMIT 1
	) pt
	 FROM wegvakken_points_pa a
)
,wegvakken_points_z AS (
	SELECT ogc_fid, path, ST_Translate(ST_Force3D(geom), 0,0,PC_Get(first(pt),'z') + 1.5) geom
	FROM wegvakken_points_pt 
	GROUP BY ogc_fid, path, geom
	ORDER BY ogc_fid, path
)
,wegvakken_z AS (
	SELECT ogc_fid, ST_MakeLine(geom) geom
	FROM wegvakken_points_z 
	GROUP BY ogc_fid
)
SELECT ogc_fid id,'wegvak' as type, 'red' as color, ST_AsX3D(geom) geom, 'wegvak' As label
FROM wegvakken_z
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
