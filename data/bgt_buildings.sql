WITH 
bounds AS (
	--SELECT ST_Buffer(ST_Transform(ST_SetSrid(ST_MakePoint(_lon, _lat),4326), 28992),200) geom
	SELECT ST_MakeEnvelope(_west, _south, _east, _north, 28992) geom
), 
pointcloud AS (
	SELECT PC_FilterEquals(pa,'classification',6) pa 
	FROM ahn3_pointcloud.vw_ahn3, bounds 
	WHERE ST_DWithin(geom, Geometry(pa),10) --patches should be INSIDE bounds
),
footprints AS (
	SELECT ST_Force3D(ST_GeometryN(ST_SimplifyPreserveTopology(wkb_geometry,0.4),1)) geom,
	a.ogc_fid id,
	0 bouwjaar
	FROM bgt.pand_2dactueelbestaand a, bounds b
	WHERE 1 = 1
	--AND a.ogc_fid = 688393 --DEBUG
	AND ST_Area(a.wkb_geometry) > 30
	AND ST_Intersects(a.wkb_geometry, b.geom)
	AND ST_Intersects(ST_Centroid(a.wkb_geometry), b.geom)
	AND ST_IsValid(a.wkb_geometry)
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
		PC_PatchAvg(PC_Union(pa),'z') AS max,
		PC_PatchMin(PC_Union(pa),'z') AS min,
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
		(
			ST_Extrude(
				ST_Translate(footprint,0,0, min - 1) --pull 1 meter down
			, 0,0,max-min -1)
		) 
		geom FROM stats_fast
	--SELECT ST_Tesselate(ST_Translate(footprint,0,0, min + 20)) geom FROM stats_fast
)
SELECT id,
--s.type as type,
'building' as type,
'red' color, ST_AsX3D((p.geom)) geom
FROM polygons p
WHERE p.geom Is Not Null --this can happen with not patch
