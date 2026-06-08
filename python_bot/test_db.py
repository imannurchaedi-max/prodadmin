import psycopg2

try:
    conn = psycopg2.connect(
        host='localhost',
        port=5432,
        dbname='prod_admin',
        user='postgres',
        password='SASMU123'
    )
    cur = conn.cursor()
    
    cur.execute('SELECT version();')
    print('PostgreSQL Version:', cur.fetchone()[0])
    
    cur.execute("SELECT table_name FROM information_schema.tables WHERE table_schema='public';")
    tables = [row[0] for row in cur.fetchall()]
    print('\nTables in DB:')
    for table in tables:
        print(f" - {table}")
        
    conn.close()
    print('\n[Koneksi Berhasil!]')
except Exception as e:
    print('Gagal koneksi ke DB:', e)
