#!/usr/bin/env python3
"""Upgrade/replay audit in newly-created disposable databases; never a live schema."""
import json,os,pathlib,re,subprocess,sys,uuid
if os.environ.get('READINESS_DB_TESTS')!='1':sys.exit('SKIP: READINESS_DB_TESTS=1 required')
root=pathlib.Path(__file__).resolve().parents[1]
container='inventory-readiness-db';suffix=uuid.uuid4().hex[:8]
def run(sql,db=None,check=True):
 cmd=['docker','exec','-i',container,'mariadb','--batch','--skip-column-names','-uroot','-paudit-root-only']+([db] if db else [])
 r=subprocess.run(cmd,input=sql,text=True,capture_output=True)
 if check and r.returncode:raise RuntimeError(r.stderr[-2000:])
 return r
upgrade='readiness_upgrade_'+suffix+'_asset_tag_test';fresh='readiness_fresh_'+suffix+'_asset_tag_test'
for db in [upgrade,fresh]:run('CREATE DATABASE `'+db+'`')
base=subprocess.check_output(['git','show','HEAD:database/schema.sql'],cwd=root,text=True)
base=re.sub(r'(?m)^(CREATE DATABASE.*|USE .*);\s*','',base)
run(base,upgrade)
run((root/'database/schema.sql').read_text(),fresh)
seed="""INSERT INTO users(id,full_name,username,password_hash,role) VALUES(1,'Migration fixture','migration_fixture','not-a-login','admin');
INSERT INTO categories(id,name) VALUES(1,'Migration fixture');
INSERT INTO products(id,category_id,name) VALUES(1,1,'Consumed legacy receipt');
INSERT INTO inventory_transactions(id,product_id,transaction_type,quantity,previous_quantity,new_quantity,reason,unit_price,total_price,status,created_by,transaction_at) VALUES(1,1,'ordered',2,0,0,'New',100,200,'Confirmed',1,'2026-01-01 09:00:00');
INSERT INTO inventory_transactions(id,product_id,transaction_type,quantity,previous_quantity,new_quantity,reason,related_order_id,status,created_by,transaction_at) VALUES(2,1,'in',2,0,2,'New',1,'Received',1,'2026-01-02 09:00:00');
INSERT INTO inventory_transactions(id,product_id,transaction_type,quantity,previous_quantity,new_quantity,reason,created_by,transaction_at) VALUES(3,1,'out',-1,2,1,'Disposed',1,'2026-01-03 09:00:00');
INSERT INTO audit_logs(user_id,action,entity_type,entity_id,details) VALUES(1,'fixture','product',1,'Preserve this audit row');"""
run(seed,upgrade)
original=run('SELECT id,product_id,transaction_type,quantity,previous_quantity,new_quantity,unit_price,total_price,status FROM inventory_transactions ORDER BY id; SELECT * FROM audit_logs;',upgrade).stdout
results=[]
for pass_number in [1,2]:
 for file in sorted((root/'database/migrations').glob('*.sql')):
  result=run(file.read_text(),upgrade,False)
  results.append({'migration':file.name,'pass':pass_number,'status':'PASS' if result.returncode==0 else 'FAIL','error':result.stderr[-600:] if result.returncode else ''})
  if result.returncode:print('FAIL',pass_number,file.name,result.stderr[-180:],flush=True)
current=run('SELECT id,product_id,transaction_type,quantity,previous_quantity,new_quantity,unit_price,total_price,status FROM inventory_transactions ORDER BY id; SELECT * FROM audit_logs;',upgrade).stdout
assert original==current,'Upgrade rewrote original transaction or audit data'
columns="SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COALESCE(COLUMN_DEFAULT,'<NULL>') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION"
u=set(run(columns,upgrade).stdout.splitlines());f=set(run(columns,fresh).stdout.splitlines())
layer=run('SELECT COUNT(*) FROM inventory_cost_layers WHERE source_transaction_id=2',upgrade).stdout.strip()
report={'upgrade_database':upgrade,'fresh_database':fresh,'migrations':results,'fresh_only_columns':sorted(f-u),'upgrade_only_columns':sorted(u-f),'business_rows_preserved':original==current,'consumed_legacy_receipt_layer_count':int(layer)}
path='/tmp/inventory-readiness-audit/migration-results.json';pathlib.Path(path).write_text(json.dumps(report,indent=2))
print(json.dumps({'migration_checks':len(results),'failed':sum(r['status']=='FAIL' for r in results),'schema_differences':len(f-u)+len(u-f),'legacy_receipt_layers':int(layer),'report':path}))
sys.exit(any(r['status']=='FAIL' for r in results) or bool(f-u) or bool(u-f) or int(layer)>0)
