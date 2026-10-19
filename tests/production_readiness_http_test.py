#!/usr/bin/env python3
"""Real HTTP regressions. Run only against an explicitly disposable audit container.
READINESS_HTTP_TESTS=1 python3 tests/production_readiness_http_test.py
The container must expose localhost:8096 and use a *_asset_tag_test database.
"""
import concurrent.futures, datetime, hashlib, json, os, re, subprocess, sys, uuid
import requests

if os.environ.get('READINESS_HTTP_TESTS') != '1':
    sys.exit('SKIP: set READINESS_HTTP_TESTS=1 for the disposable audit environment.')
BASE = os.environ.get('READINESS_BASE_URL', 'http://127.0.0.1:8096/').rstrip('/') + '/'
CONTAINER = os.environ.get('READINESS_CONTAINER', 'inventory-readiness-app')
if not BASE.startswith(('http://127.0.0.1:', 'http://localhost:')) or not CONTAINER.startswith('inventory-readiness-'):
    sys.exit('Refusing a nonlocal/non-audit target.')
PASSWORD = 'Audit-Only-Password-2026'
PREFIX = 'readiness_' + uuid.uuid4().hex[:9]
RESULTS = []

def php(code, data='', user=None):
    p = subprocess.run(['docker','exec','-i']+(['-u',user] if user else [])+[CONTAINER,'php','-r',code], input=data, text=True, capture_output=True)
    if p.returncode:
        raise AssertionError('PHP probe failed: ' + p.stderr[-1200:])
    return p.stdout

def sql(statement, params=()):
    code = 'require "config.php";if(!str_ends_with(db()->query("SELECT DATABASE()")->fetchColumn(),"_asset_tag_test"))exit(9);$x=json_decode(stream_get_contents(STDIN),true);$q=db()->prepare($x[0]);$q->execute($x[1]);echo json_encode($q->fetchAll());'
    return json.loads(php(code, json.dumps([statement,list(params)])))

def check(value, message):
    if not value: raise AssertionError(message)

def field(response, name):
    m=re.search(r'name="'+re.escape(name)+r'"[^>]*value="([^"]*)"',response.text)
    check(m, 'Missing field '+name+' at '+response.url)
    return m[1]

def get(session, path): return session.get(BASE+path,timeout=15)
def post(session, path, data, token_page=None, follow=True):
    data=dict(data)
    if 'csrf' not in data: data['csrf']=field(get(session,token_page or path),'csrf')
    return session.post(BASE+path,data=data,allow_redirects=follow,timeout=20)

def login(username, password=PASSWORD):
    s=requests.Session();r=get(s,'login.php');before=s.cookies.get('PHPSESSID')
    r=post(s,'login.php',{'csrf':field(r,'csrf'),'username':username,'password':password},follow=False)
    check(r.status_code==302,'Login failed: '+str(r.status_code))
    check(s.cookies.get('PHPSESSID')!=before,'Login did not rotate session ID')
    return s

def case(name, function):
    try: function();RESULTS.append({'name':name,'status':'PASS'});print('PASS:',name,flush=True)
    except Exception as e: RESULTS.append({'name':name,'status':'FAIL','error':str(e)});print('FAIL:',name,str(e),flush=True)

# This safety check precedes every write. Bootstrap is exercised separately on an empty database.
check(sql('SELECT DATABASE() name')[0]['name'].endswith('_asset_tag_test'),'Wrong database')
sql('DELETE FROM login_attempt_limits')
admin=login('readiness_admin_0')
admin_id=sql('SELECT id FROM users WHERE username=?',['readiness_admin_0'])[0]['id']

def create_user(role, suffix, credential=False):
    username=PREFIX+'_'+suffix
    data={'action':'create_user','full_name':username,'username':username,'password':PASSWORD,'role':role,'credential_access_mode':'role'}
    post(admin,'users.php',data)
    rows=sql('SELECT id FROM users WHERE username=?',[username]);check(rows,'User creation failed')
    return rows[0]['id'],username
viewer_id,viewer_name=create_user('viewer','viewer');viewer=login(viewer_name)
member_id,member_name=create_user('viewer','credential')

post(admin,'categories.php',{'action':'category_create','name':PREFIX,'description':'Audit fixture <script>window.auditXss=1</script>'})
category_id=sql('SELECT id FROM categories WHERE name=?',[PREFIX])[0]['id']
post(admin,'categories.php',{'action':'subcategory_create','category_id':category_id,'name':PREFIX+' sub'})
subcategory_id=sql('SELECT id FROM sub_categories WHERE category_id=?',[category_id])[0]['id']
post(admin,'vendors.php',{'action':'save','vendor_name':PREFIX,'contact_person':'Audit contact','phone':'+91 9876543210','email':'audit@example.invalid','address':'Audit location'})
vendor_id=sql('SELECT id FROM vendors WHERE vendor_name=?',[PREFIX])[0]['id']
post(admin,'employees.php',{'action':'employee_create','employee_name':PREFIX,'employee_code':PREFIX,'department':'__new__','new_department':PREFIX,'location':'Long location name for tablet and mobile layout checks'})
employee_id=sql('SELECT id FROM employees WHERE employee_id=?',[PREFIX])[0]['id']
post(admin,'employees.php',{'action':'employee_create','employee_name':PREFIX+' other','employee_code':PREFIX+'other','department':'__new__','new_department':PREFIX+' other'})
other_employee=sql('SELECT id FROM employees WHERE employee_id=?',[PREFIX+'other'])[0]['id']

def product(suffix, serialized=0):
    post(admin,'products.php',{'action':'create','name':PREFIX+' '+suffix,'category_id':category_id,'sub_category_id':subcategory_id,'unit':'1','minimum_stock':'1',**({'serialized':'1'} if serialized else {})})
    rows=sql('SELECT id FROM products WHERE name=?',[PREFIX+' '+suffix]);check(rows,'Product create failed')
    return rows[0]['id']

def receive(pid, qty, price, days=0, order=None, key=None):
    path='products.php?manage='+str(pid);r=get(admin,path)
    data={'action':'stock','operation':'receive','product_id':pid,'quantity':qty,'unit_price':price,'reason':'New','vendor_id':vendor_id,'received_date':str(datetime.date.today()-datetime.timedelta(days=days)),'request_key':key or uuid.uuid4().hex+uuid.uuid4().hex,'csrf':field(r,'csrf')}
    if order: data['order_id']=order
    return post(admin,path,data)

def remove(pid,qty,session=None,route='products',key=None):
    session=session or admin
    if route=='products':
        return post(session,'products.php?manage='+str(pid),{'action':'stock','operation':'out','product_id':pid,'quantity':qty,'reason':'Disposed','request_key':key or uuid.uuid4().hex+uuid.uuid4().hex})
    r=get(session,'inventory.php')
    return post(session,'inventory.php',{'csrf':field(r,'csrf'),'product_id':pid,'quantity':qty,'transaction_type':'out','reason':'Disposed','order_request_key':field(r,'order_request_key')})

def stock(pid): return int(sql("SELECT COALESCE(SUM(CASE WHEN transaction_type='ordered' THEN 0 ELSE quantity END),0) qty FROM inventory_transactions WHERE product_id=?",[pid])[0]['qty'])
def tx(pid): return sql('SELECT id,transaction_type,quantity,unit_price,total_price,new_quantity FROM inventory_transactions WHERE product_id=? ORDER BY id',[pid])

def auth_access():
    for route in ['users.php','create-user.php','credential-access.php','inventory-cost-reconciliation.php','credentials.php','credential-totp-setup.php?user_id='+str(admin_id)]:
        check(get(viewer,route).status_code==403,'Viewer accessed '+route)
    for route in ['dashboard.php','products.php','inventory.php','history.php','employees.php','assignments.php','hardware-assets.php','orders.php','vendors.php']:
        check(get(requests.Session(),route).url.endswith('login.php'),'Anonymous accessed '+route)
    before=sql('SELECT COUNT(*) n FROM inventory_transactions')[0]['n']
    r=post(viewer,'inventory.php',{'product_id':1,'transaction_type':'out','quantity':'1','csrf':'invalid'},follow=False)
    check(r.status_code==403,'Viewer stock write not denied')
    r=post(admin,'categories.php',{'action':'category_create','name':PREFIX+'badcsrf','csrf':'invalid'},follow=False)
    check(r.status_code==419,'CSRF not rejected')
    check(sql('SELECT COUNT(*) n FROM inventory_transactions')[0]['n']==before,'Unauthorized write changed stock')
    check(get(admin,'logout.php').status_code==405,'Logout GET mutates session')
    s=login(viewer_name);r=post(s,'logout.php',{},token_page='dashboard.php',follow=False)
    check(r.status_code==302 and get(s,'dashboard.php').url.endswith('login.php'),'Logout did not invalidate session')
case('Login/session rotation, logout, direct URL RBAC and CSRF',auth_access)

pid=product('cost history')
def costs():
    receive(pid,5,'100.00',days=1);original=tx(pid)[0]
    remove(pid,2)
    rows=tx(pid);check(rows[0]['total_price']=='500.00','Removal rewrote original receipt price')
    check(rows[1]['total_price']=='200.00','Removal has no original FIFO cost')
    remove(pid,1,route='inventory');rows=tx(pid)
    check(rows[-1]['total_price']=='100.00','Inventory removal did not allocate cost')
    check(sql('SELECT remaining_quantity FROM inventory_cost_layers WHERE product_id=?',[pid])[0]['remaining_quantity']==2,'Layer quantity differs from physical available stock')
    check(stock(pid)==2,'Wrong stock after reductions')
case('Both stock-removal routes preserve receipt prices and consume costs',costs)

def backdates():
    receive(pid,3,'120.00',days=3)
    actual=php('require "inventory_helpers.php";echo currentStock('+str(pid)+');')
    check(int(actual)==5,'Backdated receipt excluded from current stock')
    before=stock(pid);receive(pid,1,'50.00',days=-3);check(stock(pid)==before,'Future receipt accepted')
case('Backdated receipts included; future receipts rejected',backdates)

def duplicates_and_race():
    p=product('concurrent');key=uuid.uuid4().hex+uuid.uuid4().hex
    receive(p,2,'25.00',key=key);receive(p,2,'25.00',key=key)
    check(stock(p)==2 and len(tx(p))==1,'Duplicate receipt increased stock')
    sessions=[login('readiness_admin_0'),login('readiness_admin_0')]
    with concurrent.futures.ThreadPoolExecutor() as pool:list(pool.map(lambda s:remove(p,2,s),sessions))
    check(stock(p)==0 and len(tx(p))==2,'Concurrent removal oversold inventory')
    remove(p,1);check(stock(p)==0,'Negative stock accepted')
case('Duplicate receipts and concurrent negative-stock prevention',duplicates_and_race)

def order_receipts():
    p=product('order');r=get(admin,'inventory.php')
    data={'csrf':field(r,'csrf'),'product_id':p,'transaction_type':'ordered','quantity':'3','unit_price':'40.00','order_reason':'New','vendor_id':vendor_id,'order_request_key':field(r,'order_request_key')}
    post(admin,'inventory.php',data);post(admin,'inventory.php',data)
    order=tx(p)[0]['id'];check(stock(p)==0 and len(tx(p))==1,'Order changed stock or duplicated')
    receive(p,1,'40.00',order=order);check(stock(p)==0,'Pending order received')
    post(admin,'orders.php',{'action':'confirm','order_id':order})
    check(stock(p)==0,'Approval increased stock')
    receive(p,1,'40.00',order=order);check(stock(p)==1,'Partial receipt failed')
    receive(p,3,'40.00',order=order);check(stock(p)==1,'Excess receipt accepted')
    receive(p,2,'40.00',order=order);check(stock(p)==3,'Final receipt failed')
    check(sql('SELECT status FROM inventory_transactions WHERE id=?',[order])[0]['status']=='Confirmed','Receipt overwrote order approval status')
    receive(p,1,'40.00',order=order);check(stock(p)==3,'Fully received order received again')
case('Order creation/approval separate from partial/full receiving',order_receipts)

def assignments():
    p=product('assignment');receive(p,1,'100.00');receive(p,1,'120.00')
    post(admin,'assignments.php',{'employee_record_id':employee_id,'product_id':p,'quantity':'2'})
    a=sql('SELECT id FROM assignments WHERE product_id=?',[p])[0]['id']
    check(stock(p)==0,'Assignment did not transfer stock')
    page=get(admin,'employee.php?id='+str(employee_id)).text
    check('₹220.00' in page,'Assigned employee valuation missing')
    post(admin,'assignments.php',{'action':'assignment_return','assignment_id':a,'return_quantity':'1'})
    check(stock(p)==1,'Partial return failed')
    post(admin,'assignments.php',{'action':'assignment_return','assignment_id':a,'return_quantity':'1'})
    rows=sql('SELECT action,event_quantity,acquisition_value FROM hardware_assignment_history WHERE assignment_id=? ORDER BY id',[a])
    check([r['acquisition_value'] for r in rows]==['220.00','100.00','120.00'],'Return costs lost original acquisition basis')
    page=get(admin,'employee.php?id='+str(employee_id)).text
    check(page.count('₹220.00')>=2 and '₹0.00' in page,'Historical values disappeared at zero active count')
    post(admin,'assignments.php',{'employee_record_id':other_employee,'product_id':p,'quantity':'1'})
    check('₹220.00' in get(admin,'employee.php?id='+str(employee_id)).text,'Reassignment erased former employee history')
    check(len(sql('SELECT id FROM hardware_assignment_history WHERE assignment_id=?',[a]))==3,'Duplicate hardware history')
case('Bulk assignment, partial/full returns, historical totals and reassignment',assignments)

def security_pages():
    r=get(admin,'categories.php');check('<script>window.auditXss=1</script>' not in r.text,'Stored XSS in category')
    for route in ['.env','.git/config','schema.sql','backup.zip','bin/process_asset_tag_jobs.php','bin/process_order_email_outbox.php','bin/send_order_reminders.php','vendor/autoload.php']:
        r=get(requests.Session(),route);check(r.status_code in (403,404),'Sensitive path accessible: '+route+' '+str(r.status_code))
    for header in ['X-Content-Type-Options','X-Frame-Options','Content-Security-Policy','Referrer-Policy']:
        check(header in get(admin,'dashboard.php').headers,'Missing header '+header)
case('Stored XSS escaping, protected files/workers and security headers',security_pages)

# Grant one department, and explicitly enable credential capabilities for a non-admin.
post(admin,'users.php',{'action':'save_user_permissions','target_user_id':member_id,'access_mode':'custom','user_permissions[credentials][]':['view','create','edit','reveal','delete'],'user_permissions[employees][]':['view'],'user_permissions[software][]':['view'],'user_permissions[search][]':['view'],'user_permissions[dashboard][]':['view']})
member=login(member_name)
secret=PREFIX+'-SECRET-<>&'
post(admin,'credential-access.php',{'action':'add_department','department_name':PREFIX})
post(admin,'credential-access.php',{'action':'add_department','department_name':PREFIX+' other'})
post(admin,'credentials.php',{'action':'create','employee_id':employee_id,'service_name':PREFIX+' credential','credential_username':'fixture','credential_password':secret,'service_url':'https://example.invalid/'})
cred=sql('SELECT id,department_id,credential_password_enc FROM credentials WHERE service_name=?',[PREFIX+' credential'])[0]
post(admin,'credential-access.php',{'action':'grant','user_id':member_id,'department_id':cred['department_id']})
post(admin,'credentials.php',{'action':'create','employee_id':other_employee,'service_name':PREFIX+' forbidden','credential_password':secret})
other_cred=sql('SELECT id FROM credentials WHERE service_name=?',[PREFIX+' forbidden'])[0]['id']

def credentials():
    check(cred['credential_password_enc']!=secret,'Plaintext credential in database')
    for path in ['credentials.php','credentials.php?edit='+str(cred['id']),'credentials.php?view='+str(cred['id'])]:
        text=get(member,path).text;check(secret not in text and cred['credential_password_enc'] not in text,'Credential secret in HTML')
    check(get(member,'credentials.php?edit='+str(other_cred)).status_code in (403,404),'Cross-department edit IDOR')
    r=post(member,'credential-reveal.php',{'credential_id':other_cred,'reauth_password':PASSWORD},token_page='credentials.php',follow=False)
    check(r.status_code in (403,404) and secret not in r.text,'Cross-department reveal IDOR')
    r=post(member,'credential-reveal.php',{'credential_id':cred['id'],'reauth_password':PASSWORD},token_page='credentials.php',follow=False)
    check(r.status_code==200 and r.json().get('password')==secret,'Authorized reveal failed')
    check('no-store' in r.headers.get('Cache-Control',''),'Reveal cacheable')
    post(member,'credentials.php',{'action':'update','credential_id':cred['id'],'employee_id':employee_id,'service_name':PREFIX+' credential','credential_password':secret+'new'})
    check(len(sql('SELECT id FROM credential_password_versions WHERE credential_id=?',[cred['id']]))==1,'Password version history missing')
    check(sql("SELECT COUNT(*) n FROM credential_access_logs WHERE credential_id=? AND action='revealed'",[cred['id']])[0]['n']==1,'Reveal audit missing/duplicated')
    r=post(member,'credential-attachment.php',{'credential_id':other_cred,'action':'delete','attachment_id':'999'},token_page='credentials.php',follow=False)
    check(r.status_code in (400,404),'Cross-department attachment operation accepted')
    post(member,'credentials.php',{'action':'delete','credential_id':cred['id']})
    check(sql('SELECT active FROM credentials WHERE id=?',[cred['id']])[0]['active']==0,'Credential deletion not soft')
    check(len(sql('SELECT id FROM credential_access_logs WHERE credential_id=?',[cred['id']]))>=3,'Credential deletion erased access history')
case('Credential CRUD, encryption, reveal, department isolation and audit preservation',credentials)

def session_invalidation():
    s=login(viewer_name)
    post(admin,'users.php',{'action':'deactivate_user','target_user_id':viewer_id,'confirm_account_status':'1'})
    check(get(s,'dashboard.php').url.endswith('login.php'),'Deactivated user session survived')
    post(admin,'users.php',{'action':'reactivate_user','target_user_id':viewer_id,'confirm_account_status':'1'})
    s=login(viewer_name)
    sid=s.cookies.get('PHPSESSID')
    php('session_id('+json.dumps(sid)+');require "config.php";$_SESSION["last_activity"]=time()-1900;',user='www-data')
    check(get(s,'dashboard.php').url.endswith('login.php'),'Idle session did not expire')
    post(admin,'users.php',{'action':'deactivate_user','target_user_id':admin_id,'confirm_account_status':'1'})
    check(sql('SELECT active FROM users WHERE id=?',[admin_id])[0]['active']==1,'Self-deactivation allowed')
case('Account activation/deactivation, session expiry and self-deactivation protection',session_invalidation)

def throttle():
    s=requests.Session();r=get(s,'login.php');token=field(r,'csrf')
    statuses=[post(s,'login.php',{'csrf':token,'username':viewer_name,'password':'invalid'},follow=False).status_code for _ in range(11)]
    check(statuses[-1]==429,'Login attempts never throttled')
    other=requests.Session();r=get(other,'login.php')
    check(post(other,'login.php',{'csrf':field(r,'csrf'),'username':viewer_name,'password':PASSWORD},follow=False).status_code==429,'New session bypassed account throttle')
case('Persistent login throttling across sessions',throttle)

fixtures={'base_url':BASE,'username':'readiness_admin_0','employee_id':employee_id,'product_id':pid,'category_id':category_id,'viewer_id':viewer_id,'member_id':member_id}
output=os.environ.get('READINESS_RESULTS','/tmp/inventory-readiness-audit/http-results.json')
with open(output,'w') as f: json.dump({'tests':RESULTS,'fixtures':fixtures},f,indent=2)
print(json.dumps({'passed':sum(x['status']=='PASS' for x in RESULTS),'failed':sum(x['status']=='FAIL' for x in RESULTS),'results':output}))
sys.exit(any(x['status']=='FAIL' for x in RESULTS))
