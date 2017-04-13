/*
SELECT _south::text || _west::text || '1' AS id, 'land' AS type,
St_AsX3D(ST_SetSrid(ST_Triangulate2DZ(ST_Collect(ST_SetSrid(St_MakePoint(x,y),28992)), 0),28992),3,1)  
FROM ahn3_c30_fz1
WHERE x > CAST(_west3 AS double) 
AND x < CAST(_east AS double) and y between CAST(_south AS double) and CAST(_north AS double);
*/

WITH points AS (
SELECT x,y,z FROM ahn3_c30_fz1
WHERE x between _west.0 AND _east.0
AND y between _south.0 AND  _north.0
AND c = 2
SAMPLE 1000
)
SELECT '1' AS id, 'land' AS type,
St_AsX3D(ST_SetSrid(ST_Triangulate2DZ(ST_Collect(ST_SetSrid(St_MakePoint(x,y,z),28992)), 0),28992),3,0)
