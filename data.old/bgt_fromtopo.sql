
WITH
bounds AS (
	SELECT ST_Segmentize(ST_MakeEnvelope(_west, _south, _east, _north, 28992),_segmentlength) geom
)
,geometries AS (
	SELECT 
	gid,
	type,
	CASE 
		WHEN Not ST_IsValid(geom) THEN
			ST_MakeValid(geom)
		ELSE geom
	END as geom
	FROM tmp.tmp
)

,triangles AS(
	SELECT 
	gid,
	geom,
	type,
	ST_MakePolygon(
		ST_ExteriorRing(
			(ST_Dump(ST_Triangulate2DZ((geom)))).geom
		)
	) triang
	FROM geometries
	WHERE ST_IsValid(geom)
	--GROUP BY gid, geom
)
,assign_triags AS (
	SELECT 	a.gid, a.type,a.triang as geom
	FROM triangles a
	INNER JOIN tmp.tmp b
	ON ST_Contains(b.geom, a.triang)
	AND a.gid = b.gid
)

SELECT _south::text || _west::text || gid as id, 
	ST_AsX3D(ST_Collect(p.geom)) geom, type
FROM  
assign_triags p, 
bounds a
WHERE ST_Intersects(a.geom, p.geom)
GROUP BY gid,type;