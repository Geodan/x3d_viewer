WITH 
bounds AS (
	--SELECT ST_Buffer(ST_Transform(ST_SetSrid(ST_MakePoint(_lon, _lat),4326), 28992),200) geom
	SELECT ST_MakeEnvelope(_west, _south, _east, _north, 28992) geom
),

polygons AS (
	SELECT 
	d.gmlid as id,
	--x3d_diffuse_color as color,
	d.solid_geometry,
	c.lod2_terrain_intersection
	FROM citydb.cityobject a
	INNER JOIN bounds b ON ST_Intersects(a.envelope, b.geom)
	INNER JOIN citydb.building c ON (a.id = c.id)
	INNER JOIN citydb.surface_geometry d ON (c.lod2_solid_id = d.id)
	--INNER JOIN citydb.surface_data e ON (c.lod2_solid_id = e.id)
	--WHERE c.building_root_id = c.id
				  
),
rings AS (
	SELECT 
		generate_series(1,floor((ST_Zmax(solid_geometry)-ST_Zmin(solid_geometry))/2.5)::int) floornr,
		ST_Zmin(solid_geometry) zmin, 
		ST_Zmax(solid_geometry) zmax,
		ST_Translate(
			ST_Scale(lod2_terrain_intersection,1.05,1.05)
			,ST_X(ST_Centroid(lod2_terrain_intersection))*(1 - 1.05)
			,ST_Y(ST_Centroid(lod2_terrain_intersection))*(1 - 1.05)
		) geom
		FROM polygons p
)
,faces AS (
	SELECT floornr, (ST_Dump(geom)).geom FROM rings
)
,contours AS (
	SELECT a.wkb_geometry geom, from_ 
	FROM contour_pzh a, bounds b
	WHERE ST_Intersects(a.wkb_geometry, b.geom)
),
segments AS (
	SELECT floornr 
	,n.from_ - (0.5 * floornr) AS influx
	,p.geom
	FROM faces p
	INNER JOIN contours n ON ST_Intersects(n.geom, ST_Centroid(p.geom))
	WHERE ST_Length(p.geom) > 2
	AND n.from_ > 50
)

SELECT 1 as id,
--s.type as type,
'facade' as type,
CASE 
	WHEN influx <= 45 THEN 'white'
	WHEN influx <= 50 THEN 'lightblue'
	WHEN influx <= 55 THEN 'green'
	WHEN influx <= 60 THEN 'yellow'
	WHEN influx <= 65 THEN 'orange'
	WHEN influx <= 70 THEN 'red'
	ELSE 'white' 
END AS color,
ST_AsX3D(
	ST_Translate(ST_Extrude( p.geom ,0,0,2.5),0,0,2.5*(floornr-1)) 
) geom
FROM segments p
WHERE influx >= 50