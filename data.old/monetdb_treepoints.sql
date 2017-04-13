WITH points AS (
SELECT x,y,z FROM ahn3_c30_fz1
WHERE
c = 1
AND r < n -1
AND i < 150
AND x between _west.0 AND _east.0
AND y between _south.0 AND  _north.0

SAMPLE 1000
)
SELECT '1' AS id, 'tree' AS type, 'green' As color,
St_AsX3D(ST_Collect(ST_SetSrid(St_MakePoint(x,y,z),28992)),3,0);