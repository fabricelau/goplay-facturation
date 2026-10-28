/* Goplay Facturation — couche de compatibilité WordPress.
   Fournit window.claude.use('db' | 'downloads' | 'mcp') au-dessus de l'API REST du site. */
(function(){
  var G=window.GPF||{};
  function req(method,path,body){
    return fetch(G.rest+path,{method:method,credentials:'same-origin',headers:{'X-WP-Nonce':G.nonce,'Content-Type':'application/json'},body:body===undefined?undefined:JSON.stringify(body)})
      .then(function(r){return r.json().catch(function(){return {};}).then(function(j){
        if(r.status===401||r.status===403){var e=new Error('Session expirée. Recharge la page et reconnecte-toi.');e.code='not_granted';throw e;}
        if(!r.ok){var e2=new Error((j&&j.message)||('Erreur '+r.status));e2.code=(j&&j.code)||'error';throw e2;}
        return j;});});
  }
  /* ---------------- db ---------------- */
  var data={};               // coll -> {id: body}
  var loaded=false, colSubs={}, docSubs={}, colErr={}, writes=0;
  function snapDoc(id,body){return {id:id,exists:!!body,data:function(){return body;},metadata:{fromCache:false,hasPendingWrites:false}};}
  function notifyColl(c){(colSubs[c]||[]).forEach(function(fn){var m=data[c]||{};fn({docs:Object.keys(m).map(function(id){return snapDoc(id,m[id]);}),size:Object.keys(m).length,empty:!Object.keys(m).length,docChanges:function(){return [];},metadata:{fromCache:false,hasPendingWrites:false}});});}
  function notifyDoc(p){var s=p.split('/');var body=(data[s[0]]||{})[s[1]];(docSubs[p]||[]).forEach(function(fn){fn(snapDoc(s[1],body));});}
  function notifyAll(){Object.keys(colSubs).forEach(notifyColl);Object.keys(docSubs).forEach(notifyDoc);}
  var inflight=null;
  function refresh(){
    if(inflight)return inflight;
    var w0=writes;
    inflight=req('GET','all').then(function(j){if(writes!==w0){inflight=null;return refresh();}var nd={};(j.records||[]).forEach(function(r){(nd[r.coll]=nd[r.coll]||{})[r.id]=r.data;});
      /* Only re-render what really changed, so a periodic refresh never wipes a form being typed in */
      var first=!loaded;var old=data;data=nd;loaded=true;
      if(first){notifyAll();return;}
      Object.keys(colSubs).forEach(function(c){if(JSON.stringify(old[c]||{})!==JSON.stringify(nd[c]||{}))notifyColl(c);});
      Object.keys(docSubs).forEach(function(p){var s2=p.split('/');if(JSON.stringify((old[s2[0]]||{})[s2[1]])!==JSON.stringify((nd[s2[0]]||{})[s2[1]]))notifyDoc(p);});
    }).finally(function(){inflight=null;});
    return inflight;
  }
  var timer=null;
  function start(){if(timer)return;refresh().catch(function(e){console.error(e);if(!loaded)Object.keys(colErr).forEach(function(c){colErr[c].forEach(function(f){try{f({code:e.code||'unavailable',message:e.message});}catch(x){}});});});timer=setInterval(function(){if(document.visibilityState==='visible')refresh().catch(function(){});},20000);
    document.addEventListener('visibilitychange',function(){if(document.visibilityState==='visible')refresh().catch(function(){});});}
  function docRef(c,id){
    var p=c+'/'+id;
    return {id:id,path:p,
      get:function(){return refresh().then(function(){return snapDoc(id,(data[c]||{})[id]);});},
      set:function(body){writes++;return req('POST','rec/'+c+'/'+encodeURIComponent(id),body).then(function(){(data[c]=data[c]||{})[id]=JSON.parse(JSON.stringify(body));notifyColl(c);notifyDoc(p);});},
      update:function(patch){var cur=(data[c]||{})[id]||{};return this.set(Object.assign({},cur,patch));},
      delete:function(){writes++;return req('POST','del/'+c+'/'+encodeURIComponent(id),{}).then(function(){if(data[c])delete data[c][id];notifyColl(c);notifyDoc(p);});},
      onSnapshot:function(fn,err){(docSubs[p]=docSubs[p]||[]).push(fn);start();if(loaded)setTimeout(function(){notifyDoc(p);},0);return function(){docSubs[p]=(docSubs[p]||[]).filter(function(f){return f!==fn;});};}
    };
  }
  var db={
    doc:function(path){var s=path.split('/');return docRef(s[0],s[1]);},
    collection:function(c){return {path:c,
      doc:function(id){return docRef(c,id||('x'+Date.now().toString(36)+Math.random().toString(36).slice(2,8)));},
      onSnapshot:function(fn,err){(colSubs[c]=colSubs[c]||[]).push(fn);if(err)(colErr[c]=colErr[c]||[]).push(err);start();if(loaded)setTimeout(function(){notifyColl(c);},0);return function(){colSubs[c]=(colSubs[c]||[]).filter(function(f){return f!==fn;});};}
    };}
  };
  /* ---------------- downloads ---------------- */
  var downloads={save:function(o){return new Promise(function(res,rej){try{
    var blob=o.data instanceof Blob?o.data:new Blob([o.data],{type:/\.csv$/i.test(o.filename)?'text/csv;charset=utf-8':/\.json$/i.test(o.filename)?'application/json':'application/octet-stream'});
    var url=URL.createObjectURL(blob);var a=document.createElement('a');a.href=url;a.download=o.filename;document.body.appendChild(a);a.click();a.remove();setTimeout(function(){URL.revokeObjectURL(url);},4000);res({status:'saved'});
  }catch(e){rej({code:'unavailable',message:String(e)});}});}};
  /* ---------------- mail (replaces Gmail drafts) ---------------- */
  var mcp={callTool:function(server,tool,input){
    if(tool!=='create_draft')return Promise.reject({code:'bad_request',message:'Outil inconnu'});
    return req('POST','mail',input).then(function(j){return {payload:j,content:[]};}).catch(function(e){throw {code:e.code||'tool_error',message:e.message};});
  }};
  /* ---------------- phone app (service worker for install) ---------------- */
  try{if('serviceWorker' in navigator&&G.base)navigator.serviceWorker.register(G.base+'sw.js',{scope:G.base}).catch(function(){});}catch(e){}
  window.claude={use:function(name){return Promise.resolve(name==='db'?db:name==='downloads'?downloads:name==='mcp'?mcp:null);}};
})();
