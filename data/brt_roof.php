<?php

$north = $_REQUEST['north'];
$south = $_REQUEST['south'];
$west  = $_REQUEST['west'];
$east  = $_REQUEST['east'];


$width = $east - $west;
$height = $north - $south;
$area = $width * $height;
$zoom = 50 / $area;

$segmentlength = 1; //TODO: make dependant on zoom

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
	SELECT ST_Segmentize(ST_MakeEnvelope($west, $south, $east, $north, 28992),1) geom
),
footprints AS (
	SELECT 
		a.identificatie id,
		ST_Buffer(ST_Force2D(geometrie_28992),-0.5) geom
	FROM bagimproved_201405.pand a, bounds b
	WHERE ST_Intersects(a.geometrie_28992, b.geom)
	AND ST_Intersects(ST_Centroid(a.geometrie_28992), b.geom)
	AND eind_geldigheid Is Null
	--AND gebw_type = 'p'
	--AND a.ogc_fid = '25531' OR a.ogc_fid = '25529' OR a.ogc_fid = '25528' OR a.ogc_fid = '25610'
),
/** PART 1, find roofedge **/
roofcornerpts AS (
	SELECT id, (ST_DumpPoints(geom)).*
	FROM footprints
),
edge_points_patch AS ( --get closest patch to every vertex
	SELECT a.id, a.path, a.geom,  --find closes patch to point
	COALESCE(b.pa, 
		(
		SELECT b.pa FROM ahn_pointcloud.ahn2objects b
		ORDER BY a.geom <#> Geometry(b.pa) 
		LIMIT 1
		)
	) pa
	FROM roofcornerpts a LEFT JOIN ahn_pointcloud.ahn2objects b
	ON ST_Intersects(
		a.geom,
		geometry(pa)
	)
),
emptyz AS ( --find closest pt for every boundary point
	SELECT a.*, ( --find closest pc.pt to point
		SELECT b.pt FROM (SELECT PC_Explode(a.pa) pt ) b
		ORDER BY a.geom <#> Geometry(b.pt)
		LIMIT 1
	) pt
	FROM edge_points_patch a
)
-- assign z-value for every boundary point
,filledz AS ( 
	SELECT id,path, ST_Translate(St_Force3D(geom), 0,0,PC_Get(first(pt),'z')) geom
	FROM emptyz
	GROUP BY id, path, geom
	ORDER BY id, path
)
,polygonsz AS (
	SELECT id, ST_MakePolygon(ST_AddPoint(ST_MakeLine(geom), First(geom))) geom
	FROM filledz
	GROUP BY id
)
/** PART 2, get rooftop**/
,papoints AS ( --get points from intersecting patches
	SELECT 
		a.id,
		PC_Explode(b.pa) pt,
		geom footprint
	FROM footprints a
	LEFT JOIN ahn_pointcloud.ahn2objects b ON (ST_Intersects(a.geom, geometry(b.pa)))
),
points AS ( --get only points that fall inside building, patch them
	SELECT id, geometry(pt) geom
	FROM papoints 
	WHERE ST_Intersects(footprint, geometry(pt))
	AND random() < 0.3
	UNION
	SELECT id, St_ExteriorRing(geom) geom FROM polygonsz
	WHERE ST_IsValid(geom)
),
triags AS (
	SELECT id, 
	ST_MakePolygon(
			ST_ExteriorRing(
				(ST_Dump(ST_Triangulate2DZ(ST_Collect(geom)))).geom
			)
		) geom
	FROM points
	GROUP BY id
)
,assign_triags AS (
	SELECT 	a.*
	FROM triags a
	INNER JOIN polygonsz b
	ON ST_Contains(b.geom, a.geom)
	,bounds c
	WHERE ST_Intersects(ST_Centroid(b.geom), c.geom)
	AND a.id = b.id
)

SELECT 'roof'::text || id as id, 'roof' as type, 'red' as color, ST_AsX3D(ST_Collect(geom)) geom
FROM assign_triags
GROUP BY id";

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
