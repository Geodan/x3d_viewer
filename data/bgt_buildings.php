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
WITH 
bounds AS (
	--SELECT ST_Buffer(ST_Transform(ST_SetSrid(ST_MakePoint($lon, $lat),4326), 28992),200) geom
	SELECT ST_MakeEnvelope($west, $south, $east, $north, 28992) geom
), 
pointcloud AS (
	SELECT PC_FilterEquals(pa,'classification',6) pa 
	FROM ahn3_pointcloud.vw_ahn3, bounds 
	WHERE ST_DWithin(geom, Geometry(pa),10) --patches should be INSIDE bounds
),
footprints AS (
	SELECT ST_Force3D(ST_GeometryN(ST_SimplifyPreserveTopology(a.geom,0.4),1)) geom,
	a.ogc_fid id,
	0 bouwjaar
	FROM bgt.vw_polygons a, bounds b
	WHERE 1 = 1
	--AND a.ogc_fid = 688393 --DEBUG
	--AND bgt_status = 'bestaand'
	AND ST_Area(a.geom) > 30
	AND ST_Intersects(a.geom, b.geom)
	AND ST_Intersects(ST_Centroid(a.geom), b.geom)
	AND ST_IsValid(a.geom)
	AND a.type = 'pand'
    --AND ST_GeometryType(a.geometrie2dgrondvlak) = 'ST_MultiPolygon'
),
papoints AS ( --get points from intersecting patches
	SELECT 
		a.id,
		PC_Explode(b.pa) pt,
		geom footprint
	FROM footprints a
	LEFT JOIN pointcloud b ON (ST_Intersects(a.geom, geometry(b.pa)))
),
stats_fast AS (
	SELECT 
		PC_PatchAvg(PC_Union(pa),'z') max,
		PC_PatchMin(PC_Union(pa),'z') min,
		footprints.id,
		bouwjaar,
		geom footprint
	FROM footprints 
	--LEFT JOIN ahn_pointcloud.ahn2objects ON (ST_Intersects(geom, geometry(pa)))
	LEFT JOIN pointcloud ON (ST_Intersects(geom, geometry(pa)))
	GROUP BY footprints.id, footprint, bouwjaar
),
polygons AS (
	SELECT 
		id, bouwjaar,
		ST_Tesselate(
			ST_Extrude(
				ST_Translate(footprint,0,0, min)
			, 0,0,max-min)
		) 
		geom FROM stats_fast
	--SELECT ST_Tesselate(ST_Translate(footprint,0,0, min + 20)) geom FROM stats_fast
)
SELECT id,
--s.type as type,
'building' as type,
COALESCE(s.color, 'red') color, ST_AsX3D(ST_GeometryN(p.geom,1)) geom
FROM polygons p
LEFT JOIN bgt.vw_style s ON (s.type = 'pand')
";

$result = pg_query($conn, $query);
if (!$result) {
  echo "An error occurred.\n";
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
