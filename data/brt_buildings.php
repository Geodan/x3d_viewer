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
	--SELECT ST_Buffer(ST_Transform(ST_SetSrid(ST_MakePoint($lon, $lat),4326), 28992),200) geom
	SELECT ST_MakeEnvelope($west, $south, $east, $north, 28992) geom
), 
footprints AS (
	SELECT ST_Force3D(geometrie_28992) geom,
	a.identificatie id,
	bouwjaar
	--FROM bag.panden_nijmegen a, bounds b
	FROM bagimproved_201405.pand a, bounds b
	WHERE 1 = 1
	--AND naamnl Is Null
	AND ST_Area(a.geometrie_28992) > 30
	--AND gebw_type = 'p'
	AND status = 4
	AND ST_Intersects(a.geometrie_28992, b.geom)
	AND ST_Intersects(ST_Centroid(a.geometrie_28992), b.geom)
	--AND ogc_fid = 24713 --debug
),
papoints AS ( --get points from intersecting patches
	SELECT 
		a.id,
		PC_Explode(b.pa) pt,
		geom footprint
	FROM footprints a
	LEFT JOIN ahn_pointcloud.ahn2objects b ON (ST_Intersects(a.geom, geometry(b.pa)))
),
papatch AS (
	SELECT
		a.id, PC_PatchMin(PC_Union(pa), 'z') min
	FROM footprints a
	LEFT JOIN ahn_pointcloud.ahn2objects b ON (ST_Intersects(a.geom, geometry(b.pa)))
	GROUP BY a.id
),
footprintpatch AS ( --get only points that fall inside building, patch them
	SELECT id, PC_Patch(pt) pa, footprint
	FROM papoints WHERE ST_Intersects(footprint, Geometry(pt))
	GROUP BY id, footprint
),
stats AS (
	SELECT  a.id, footprint, 
		PC_PatchAvg(pa, 'z') max,
		min
	FROM footprintpatch a, papatch b
	WHERE (a.id = b.id)
),
stats_fast AS (
	SELECT 
		PC_PatchAvg(PC_Union(pa),'z') max,
		PC_PatchMin(PC_Union(pa),'z') min,
		footprints.id,
		bouwjaar,
		geom footprint
	FROM footprints 
	LEFT JOIN ahn_pointcloud.ahn2objects ON (ST_Intersects(geom, geometry(pa)))
	GROUP BY footprints.id, footprint, bouwjaar
),
polygons AS (
	SELECT id, bouwjaar, ST_Extrude(ST_Tesselate(ST_Translate(footprint,0,0, min)), 0,0,max-min) geom FROM stats_fast
	--SELECT ST_Tesselate(ST_Translate(footprint,0,0, min + 20)) geom FROM stats_fast
)
SELECT id,'building' as type, 'white' as color, ST_AsX3D(polygons.geom) geom, 'building' as label

FROM polygons
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
