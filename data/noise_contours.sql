CREATE SEQUENCE IF NOT EXISTS counter;
WITH 
bounds AS (
	SELECT ST_Segmentize(ST_MakeEnvelope(_west, _south, _east, _north, 28992),_segmentlength) geom
),
polygons AS (
	SELECT nextval('counter') id, from_ AS class, 'water'::text AS type, 
	  ST_Intersection(
		 a.wkb_geometry 
		 ,b.geom
	  ) as geom
	FROM public.contour_pzh a, bounds b
	WHERE ST_Intersects(a.wkb_geometry, b.geom)
)
,polygonsz AS ( 
	SELECT a.id, a.type, a.class, 
	ST_Translate(
		ST_Force3D(a.geom), 
		0,0,0
	) geom
	FROM polygons a
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
	--,bounds c
	--WHERE ST_Intersects(ST_Centroid(b.geom), c.geom)
	--AND a.id = b.id
)
SELECT _south::text || _west::text || p.id AS id, 
'contour' as type,
CASE class
	WHEN 0 THEN 'white'
	WHEN 50 THEN 'lightblue'
	WHEN 55 THEN 'green'
	WHEN 60 THEN 'yellow'
	WHEN 65 THEN 'orange'
	WHEN 70 THEN 'red' 
END AS color,
ST_AsX3D(ST_Collect(p.geom),3) as geom
FROM assign_triags p
GROUP BY p.id, p.type, p.class;