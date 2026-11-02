const {chromium}=require('playwright');const fs=require('fs');
const BASE='https://localhost:8443/';const MAIL='/home/user/staging/wordpress/wp-content/mail.log';
const out=__dirname+'/shots-e2e';fs.mkdirSync(out,{recursive:true});
const R=[];const ok=(n,c,d='')=>{R.push([c?'PASS':'FAIL',n,d]);console.log(c?'PASS':'FAIL',n,d)};
(async()=>{const b=await chromium.launch();const c=await b.newContext({viewport:{width:390,height:844},deviceScaleFactor:2,ignoreHTTPSErrors:true,serviceWorkers:'block'});const p=await c.newPage();
const u='qa'+Date.now().toString().slice(-6);const email=u+'@example.test';const pass='Qa-Prueba-'+u+'!x';
await p.goto(BASE+'registro/');ok('Formulario de registro visible',await p.isVisible('form.auth-form input[name=username]'));
await p.screenshot({path:out+'/01-registro.png'});
await p.fill('input[name=username]',u);await p.fill('input[name=email]',email);await p.fill('input[name=password]',pass);
// sin aceptar términos: el navegador lo impide (required)
ok('Casilla de términos no premarcada',!(await p.isChecked('input[name=terms_accept]')));
await p.check('input[name=terms_accept]');await Promise.all([p.waitForLoadState('load'),p.click('form.auth-form button[type=submit]')]);await p.waitForTimeout(500);
const msg=await p.innerText('.auth-card');ok('Alta creada y correo enviado',/verificación/i.test(msg),msg.split('\n').find(l=>/correo|cuenta/i.test(l))||'');
await p.screenshot({path:out+'/02-registro-ok.png'});
// login antes de confirmar debe fallar
await p.goto(BASE+'ingresar/');await p.fill('input[name=login]',u);await p.fill('input[name=password]',pass);await Promise.all([p.waitForLoadState('load'),p.click('form.auth-form button[type=submit]')]);
ok('Ingreso bloqueado antes de confirmar correo',/No fue posible ingresar/.test(await p.innerText('body')));
const mails=fs.readFileSync(MAIL,'utf8').trim().split('\n').map(JSON.parse).filter(m=>(Array.isArray(m.to)?m.to.join():m.to).includes(email));
const link=(mails.at(-1).message.match(/https:\S+/)||[])[0];ok('Correo de verificación con enlace',!!link);
await p.goto(link);const btn=await p.$('form button[type=submit]');if(btn)await Promise.all([p.waitForLoadState('load'),btn.click()]);
ok('Cuenta verificada',/verificada/i.test(await p.innerText('body')));
await p.goto(BASE+'ingresar/');await p.fill('input[name=login]',u);await p.fill('input[name=password]',pass);await Promise.all([p.waitForNavigation(),p.click('form.auth-form button[type=submit]')]);
ok('Ingreso tras confirmar',/mi-panel/.test(p.url()),p.url());
ok('Sin barra de admin para suscriptor',!(await p.$('#wpadminbar')));
// Predicción oficial
await p.goto(BASE+'predicciones/?tipo=oficiales');await p.screenshot({path:out+'/03-pred-oficiales.png',fullPage:true});
const offCards=await p.$$('.cancha-pred-card[data-origin=profesional]');const amInOff=await p.$$('.cancha-pred-card[data-origin=amateur]');
ok('Pestaña Oficiales: solo profesionales',offCards.length>0&&amInOff.length===0,`${offCards.length} oficiales / ${amInOff.length} amateur`);
const first=offCards[0];const teams=await first.$eval('.cancha-pred-match',e=>e.innerText.replace(/\s+/g,' '));
await Promise.all([p.waitForNavigation(),first.$eval('button[value="1"]',b=>b.click())]);
ok('Guardar pronóstico oficial (1)',/guardado/i.test(await p.innerText('.cancha-pred-notice')),teams);
let picked=await p.$eval('.cancha-pred-card[data-origin=profesional]',e=>e.querySelector('button.is-picked')?.value);ok('Elección marcada = 1',picked==='1');
await Promise.all([p.waitForNavigation(),p.$eval('.cancha-pred-card[data-origin=profesional] button[value="X"]',b=>b.click())]);
picked=await p.$eval('.cancha-pred-card[data-origin=profesional]',e=>e.querySelector('button.is-picked')?.value);ok('Editar antes del inicio (1→X)',picked==='X');
await p.screenshot({path:out+'/04-pred-oficial-guardada.png'});
// Predicción amateur
await p.goto(BASE+'predicciones/?tipo=amateur');const amCards=await p.$$('.cancha-pred-card[data-origin=amateur]');const offInAm=await p.$$('.cancha-pred-card[data-origin=profesional]');
ok('Pestaña Amateur: solo amateur',amCards.length>0&&offInAm.length===0,`${amCards.length} amateur / ${offInAm.length} oficiales`);
await Promise.all([p.waitForNavigation(),p.$eval('.cancha-pred-card[data-origin=amateur] button[value="2"]',b=>b.click())]);
ok('Guardar pronóstico amateur (2)',/guardado/i.test(await p.innerText('.cancha-pred-notice')));
await p.screenshot({path:out+'/05-pred-amateur-guardada.png',fullPage:true});
// mis pronósticos separados
await p.goto(BASE+'predicciones/?tipo=oficiales');const mineOff=await p.innerText('.cancha-pred-mine summary').catch(()=>'');
await p.goto(BASE+'predicciones/?tipo=amateur');const mineAm=await p.innerText('.cancha-pred-mine summary').catch(()=>'');
ok('Historial separado por origen',/oficiales \(1\)/.test(mineOff)&&/amateur \(1\)/.test(mineAm),mineOff+' | '+mineAm);
// Pronosticar desde /partidos/
await p.goto(BASE+'partidos/');const rowBtn=await p.$('.cancha-pro-row button[value="2"]');ok('Botones 1/X/2 en /partidos/ para usuario habilitado',!!rowBtn);
// Permisos: REST crear campeonato como suscriptor
const rest=await p.evaluate(async()=>{const n=window.wpApiSettings?.nonce;const r=await fetch('/wp-json/wp/v2/cancha_campeonato',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({title:'X',status:'publish'})});return r.status;});
ok('REST: suscriptor NO puede crear campeonatos',rest===401||rest===403,'HTTP '+rest);
await p.goto(BASE+'wp-admin/post-new.php?post_type=cancha_campeonato');ok('Escritorio: suscriptor NO puede crear campeonatos',!/post-new\.php/.test(p.url())||/permis|not allowed|no tienes/i.test(await p.innerText('body')),p.url());
await p.goto(BASE+'amateur/');ok('Sin botón público «Crear campeonato»',!(/Crear campeonato/i.test(await p.innerText('body'))));
fs.writeFileSync(__dirname+'/e2e-staging-'+u+'.json',JSON.stringify({user:u,results:R},null,1));
await c.storageState({path:__dirname+'/staging-user.json'});await b.close();})().catch(e=>{console.error('CRASH',e.message);process.exit(1)});
