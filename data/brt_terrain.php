<?php

$north = $_REQUEST['north'];
$south = $_REQUEST['south'];
$west  = $_REQUEST['west'];
$east  = $_REQUEST['east'];

$width = $east - $west;
$height = $north - $south;
$area = $width * $height;

$diagonal = sqrt(pow($width,2) + pow($height,2));
$zoom = 10 / $diagonal;
//$zoom  = $_REQUEST['zoom'] ?: 0.005;

$segmentlength = $width / 10;

header('Content-type: application/json');
$conn = pg_pconnect("host=192.168.24.15 dbname=research user=postgres password=postgres");
if (!$conn) {
  echo "A connection error occurred.\n";
  exit;
}
$query = "
WITH RECURSIVE
bounds AS (
	SELECT ST_Segmentize(ST_MakeEnvelope($west, $south, $east, $north, 28992),$segmentlength) geom
),
terrain AS (
	SELECT $west::text || $south::text || 't'||fid id, typelandgebruik as type,
	  --ST_Segmentize(
	  --wkb_geometry
	  (ST_Dump(
		ST_Intersection(wkb_geometry, geom)
	  )).geom
	  --,$segmentlength) As geom
	  --wkb_geometry geom
	FROM brt_201402.terrein_vlak a, bounds b
	WHERE ST_Intersects(a.wkb_geometry, b.geom)
	--WHERE ST_Intersects(ST_Centroid(a.wkb_geometry), b.geom)
),
roads AS (
	SELECT $west::text || $south::text || 't'||fid id, 'wegvlak'::text as type,
	--ST_Segmentize(
	--wkb_geometry
	(ST_Dump(
	  ST_Intersection(wkb_geometry, geom)
	)).geom
	--,$segmentlength) As geom
	--wkb_geometry geom
	FROM brt_201402.wegdeel_vlak a, bounds b
	WHERE ST_Intersects(a.wkb_geometry, b.geom)
	--WHERE ST_Intersects(ST_Centroid(a.wkb_geometry), b.geom)
	AND fysiekvoorkomen Is Null --remove bridges and tunnels
),
water AS (
	SELECT $west::text || $south::text || 'w'||fid id, 'water'::text as type,
	  (ST_Dump(
	  ST_Intersection(wkb_geometry, geom)
	)).geom As geom
	FROM brt_201402.waterdeel_vlak a, bounds b
	WHERE ST_Intersects(a.wkb_geometry, b.geom)
),
polygons AS (
	SELECT * FROM terrain
	WHERE ST_GeometryType(geom) = 'ST_Polygon'
	UNION
	SELECT * FROM roads
	WHERE ST_GeometryType(geom) = 'ST_Polygon'
	UNION
	SELECT * FROM water
	WHERE ST_GeometryType(geom) = 'ST_Polygon'
),
terrain_polygons AS (
    SELECT * FROM polygons
	WHERE type != 'wegvlak'
	AND type != 'water'
)
,breaklines AS (
	SELECT a.* FROM brt_201402.hoogteverschilhz_lijn a, bounds b
	WHERE ST_Intersects(a.wkb_geometry, b.geom)
	UNION 
	SELECT a.* FROM brt_201402.hoogteverschillz_lijn a, bounds b
	WHERE ST_Intersects(a.wkb_geometry, b.geom)
),
patches AS (
	SELECT t.id, t.type, pa
	--PC_PatchMax(pa, 'z') - PC_PatchMin(pa,'z') as delta, 
	--ST_Intersects(ST_ExteriorRing(geom), Geometry(pa)) intersects
	FROM ahn_pointcloud.ahn2terrain, terrain_polygons t
	WHERE ST_Intersects(
		geom,
		geometry(pa)
	)
),
all_points AS ( -- get pts in every boundary
	SELECT geometry(PC_Explode(pa)) geom 
	FROM ahn_pointcloud.ahn2terrain, terrain_polygons t
	WHERE ST_Intersects(
		geom,
		geometry(pa)
	)
),
--3 sets of points from the pointcloud with different densities
--1) Points close to breaklines
breakline_points AS (
	SELECT a.geom
	FROM all_points a
	INNER JOIN (SELECT ST_Collect(wkb_geometry) geom FROM breaklines) b
	ON ST_DWithin(a.geom, b.geom,0.5)
	INNER JOIN terrain_polygons c
	ON ST_Intersects(a.geom, c.geom)
	AND Not ST_DWithin(a.geom, ST_ExteriorRing(c.geom),2)
	WHERE random() < (1 * $zoom)
),
--2) points close to and inside border
border_points AS (
	SELECT a.geom
	FROM all_points a
	INNER JOIN terrain_polygons b
	ON ST_Intersects(a.geom, b.geom)
	AND ST_DWithin(a.geom, ST_ExteriorRing(b.geom),5)
	AND Not ST_DWithin(a.geom, ST_ExteriorRing(b.geom),3)
	WHERE random() < (1 * $zoom)
),
--3) other points (more distant from breakline or border)
other_points AS (
	SELECT a.geom
	FROM all_points a
	INNER JOIN terrain_polygons b
	ON ST_Intersects(a.geom, b.geom)
	AND Not ST_DWithin(a.geom, ST_ExteriorRing(b.geom),3)
	WHERE random() < (0.1 * $zoom)
)

--Put all points together
,innerpoints AS (
	--SELECT geom FROM border_points
	--UNION
	SELECT geom FROM breakline_points
	UNION
	SELECT geom FROM other_points
)
,rings AS (
	SELECT 
		id, ST_Segmentize(ST_Buffer(ST_ExteriorRing(geom),3,'endcap=flat join=bevel'),$segmentlength) geom
	FROM polygons
)
,all_ringpoints AS (
	SELECT id, ST_Force3D((ST_DumpPoints(geom)).geom) geom
	FROM rings
)
,own_ringpoints AS (
	SELECT a.id, a.geom
	FROM all_ringpoints a
	INNER JOIN polygons b
	ON ST_Intersects(a.geom, b.geom)
	AND a.id = b.id
	AND b.type != 'wegvlak'
	AND b.type != 'water'
)
,sat_points_patch AS ( --get closest patch to every vertex
	SELECT a.geom,  --find closes patch to point
	COALESCE(b.pa, 
		(
		SELECT b.pa FROM ahn_pointcloud.ahn2terrain b
		ORDER BY a.geom <#> Geometry(b.pa) 
		LIMIT 1
		)
	) pa
	FROM own_ringpoints a LEFT JOIN ahn_pointcloud.ahn2terrain b
	ON ST_Intersects(
		geom,
		geometry(pa)
	)
),
emptysatz AS ( --find closest pt for every boundary point
	SELECT a.*, ( --find closest pc.pt to point
		SELECT b.pt FROM (SELECT PC_Explode(a.pa) pt ) b
		ORDER BY a.geom <#> Geometry(b.pt)
		LIMIT 1
	) pt
	FROM sat_points_patch a
	
)
-- assign z-value for every boundary point
,filledsatz AS ( 
	SELECT ST_Translate(geom, 0,0,PC_Get(first(pt),'z')) geom
	FROM emptysatz
	GROUP BY geom
)
,satellite_points AS (
    SELECT * FROM filledsatz
)
--Now concentrate on terrainpolygon edges
,edge_points AS (
    SELECT ST_Force3D((ST_Dumppoints(geom)).geom) geom 
    FROM polygons
)
,edge_points_patch AS ( --get closest patch to every vertex
	SELECT a.geom,  --find closes patch to point
	COALESCE(b.pa, 
		(
		SELECT b.pa FROM ahn_pointcloud.ahn2terrain b
		ORDER BY a.geom <#> Geometry(b.pa) 
		LIMIT 1
		)
	) pa
	FROM edge_points a LEFT JOIN ahn_pointcloud.ahn2terrain b
	ON ST_Intersects(
		geom,
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
	SELECT ST_Translate(geom, 0,0,PC_Get(first(pt),'z')) geom
	FROM emptyz
	GROUP BY geom
)

--Put all points together
,edge_points1 AS (
	SELECT * FROM filledz
)
,basepoints AS (
	SELECT geom FROM innerpoints
	UNION
	SELECT geom FROM edge_points1
	UNION 
	SELECT geom FROM satellite_points
)
,delaunay AS (
	SELECT 
		ST_DelaunayTriangles(ST_Collect(a.geom),0,1) geom 
	FROM basepoints a

),
triangles AS (
	SELECT 
		(ST_Dump(geom)).geom
	FROM delaunay
)
,triaglines as (
	SELECT geom geom 
	FROM triangles
),
polygonsegments AS (
	SELECT ST_MakeLine(sp,ep) geom
	FROM
	-- extract the endpoints for every 2-point line segment for each linestring
	(
	SELECT
	  ST_PointN(geom, generate_series(1, ST_NPoints(geom)-1)) as sp,
	  ST_PointN(geom, generate_series(2, ST_NPoints(geom)  )) as ep
	FROM (SELECT ST_ExteriorRing(geom) geom FROM polygons) foo
	) a
)
,intersectionpoints AS (
	SELECT 
	DISTINCT	
		(ST_Dump(ST_Intersection(a.geom, b.geom))).geom geom
	FROM triangles a, polygonsegments b
	WHERE ST_Intersects(a.geom, b.geom)
	AND Not (ST_DWithin(ST_StartPoint(a.geom), b.geom,0.01))
	AND Not (ST_DWithin(ST_EndPoint(a.geom), b.geom,0.01))
	
)
,step(geom) AS (
	SELECT geom FROM intersectionpoints
	UNION ALL
	-- make new intersectionpoints
	SELECT  DISTINCT (ST_Dump(ST_Intersection(a.geom, b.geom))).geom
	FROM (
		SELECT 
		    ST_MakeLine(sp,ep) geom  
		FROM (
			SELECT
			  ST_PointN(geom, generate_series(1, ST_NPoints(geom)-1)) as sp,
			  ST_PointN(geom, generate_series(2, ST_NPoints(geom)  )) as ep
			FROM (
				SELECT (ST_Dump(geom)).geom FROM (
					SELECT 
						ST_DelaunayTriangles(ST_Collect(a.geom),0,1) geom 
					FROM (
						SELECT geom FROM basepoints
						UNION ALL
						SELECT geom FROM step
					) a
				) foo1
			) foo2
		) foo3
	) a,
	polygonsegments b
	WHERE ST_Intersects(a.geom, b.geom)
	AND Not (ST_DWithin(ST_StartPoint(a.geom), b.geom,0.01))
	AND Not (ST_DWithin(ST_EndPoint(a.geom), b.geom,0.01))
)

,crosspoints AS (
    SELECT geom FROM step LIMIT 2000 --TODO, this is obviously not good, but otherwhise there is an eternal limit
	/*SELECT (ST_Dump(ST_Intersection(a.geom, ST_ExteriorRing(b.geom)))).geom geom
	FROM triaglines a,polygons b
	WHERE ST_Crosses(a.geom, b.geom)*/
)
,points AS (
	SELECT geom FROM edge_points1
	UNION
	SELECT geom FROM innerpoints
	UNION
	SELECT geom FROM crosspoints
	WHERE ST_GeometryType(geom) = 'ST_Point'
	UNION
	SELECT geom FROM satellite_points
)
,delaunay1 AS (
	SELECT 
		ST_DelaunayTriangles(ST_Collect(a.geom),0,0) geom 
	FROM points a
),
triangles_mesh AS (
	SELECT 
		(ST_Dump(geom)).geom
	FROM delaunay1
)
,assign_triags AS (
	SELECT 	a.*, b.id, b.type
	FROM triangles_mesh a
	INNER JOIN polygons b
	ON ST_Contains(ST_Buffer(b.geom,0.2), a.geom)
)

SELECT $south::text || $west::text || id, 'terrain2' as type,
CASE
		WHEN type = 'overig' THEN 'gray'
		WHEN type = 'bebouwd gebied' THEN 'gray'
		WHEN type = 'grasland' THEN 'green'
		WHEN type = 'bos: loofbos' THEN '0 0.4 0'
		WHEN type = 'basaltblokken, steenglooiing' THEN 'black'
		WHEN type = 'wegvlak' THEN '0.4 0.4 0.4'
		WHEN type = 'water' THEN '0 0 0.6'
		WHEN type = 'zand' THEN '0.8 0.8 0.3'
		WHEN type = 'akkerland' THEN '0.8 0.7 0.3'
		ELSE 'brown'
	END as color, 
ST_AsX3D(ST_Collect(p.geom),3),type AS label
FROM assign_triags p
GROUP BY p.id, p.type
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
