WITH 
bounds AS (
	SELECT ST_MakeEnvelope(_west, _south, _east, _north, 28992) geom
)
,kades AS (
	SELECT ogc_fid, 'kade'::text AS class, 'kade'::text as type, St_Intersection(wkb_geometry, geom) geom 
	FROM bgt.scheiding_2dactueelbestaand, bounds
	WHERE (bgt_type = 'kademuur') AND ST_Intersects(geom, wkb_geometry) AND ST_GeometryType(wkb_geometry) = 'ST_Polygon'
)
,pointcloud_ground AS (
	SELECT PC_FilterEquals(pa,'classification',2) pa --ground points 
	FROM ahn3_pointcloud.vw_ahn3, bounds 
	WHERE ST_DWithin(geom, Geometry(pa),10)
),
polygons AS (
	SELECT nextval('counter') id, ogc_fid fid, COALESCE(type,'transitie') as type, class,(ST_Dump(geom)).geom
	FROM kades
)
,polygonsz AS (
	SELECT id, fid, type, class, patch_to_geom(PC_Union(b.pa), geom) geom
	FROM polygons a 
	LEFT JOIN pointcloud_ground b
	ON ST_Intersects(geom,Geometry(b.pa))
	GROUP BY id, fid, type, class, geom
)
,triangles AS (
	SELECT 
		id,
		ST_MakePolygon(
			ST_ExteriorRing(
				(ST_Dump(ST_Triangulate2DZ(a.geom))).geom
			)
		)geom
	FROM polygonsz a
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
,extruded AS (
	SELECT id, type, class, ST_Extrude(geom, 0 ,0, -4) geom
	FROM assign_triags
)
SELECT id,'kade' as type, 'grey' color, ST_AsX3D(p.geom) geom
FROM extruded p;