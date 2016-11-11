
WITH
bounds AS (
	SELECT ST_Segmentize(ST_MakeEnvelope(_west, _south, _east, _north, 28992),_segmentlength) geom
),
plantcover AS (
	SELECT ogc_fid, 'plantcover'::text AS class, bgt_fysiekvoorkomen as type, St_Intersection(wkb_geometry, geom) geom 
	FROM bgt.begroeidterreindeel_2dactueelbestaand, bounds
	WHERE eindregistratie Is Null AND ST_Intersects(geom, wkb_geometry) AND ST_GeometryType(wkb_geometry) = 'ST_Polygon'
),
bare AS (
	SELECT ogc_fid, 'bare'::text AS class, bgt_fysiekVoorkomen as type, St_Intersection(wkb_geometry, geom) geom
	FROM bgt.onbegroeidterreindeel_2dactueelbestaand, bounds
	WHERE eindregistratie Is Null AND ST_Intersects(geom, wkb_geometry) AND ST_GeometryType(wkb_geometry) = 'ST_Polygon'
),
pointcloud_ground AS (
	SELECT PC_FilterEquals(pa,'classification',2) pa 
	FROM ahn3_pointcloud.vw_ahn3, bounds
	WHERE ST_Intersects(geom, Geometry(pa))
),
polygons AS (
	SELECT nextval('counter') id, ogc_fid fid, COALESCE(type,'transitie') as type, class,(ST_Dump(geom)).geom
	FROM plantcover
	UNION ALL
	SELECT nextval('counter') id, ogc_fid fid, COALESCE(type,'transitie') as type, class,(ST_Dump(geom)).geom
	FROM bare
)
,polygonsz AS (
	SELECT id, fid, type, class, patch_to_geom(PC_Union(b.pa), geom) geom
	FROM polygons a 
	LEFT JOIN pointcloud_ground b
	ON ST_Intersects(geom,Geometry(b.pa))
	WHERE ST_GeometryType(geom) = 'ST_Polygon'
	GROUP BY id, fid, type, class, geom
)
,basepoints AS (
	SELECT id,type, class, geom FROM polygonsz
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
	GROUP BY id, type, class
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

SELECT _south::text || _west::text || p.id as id, p.type as type,
	ST_AsX3D(ST_Collect(p.geom),5) geom
FROM assign_triags p
GROUP BY p.id, p.type