import React, { useCallback, useEffect, useState } from 'react';
import { useCurrentServerRequired, toast } from '@pterodactyl/sdk';

interface Modset { id: number; name: string }
interface Assignment { modset_id: number | null; modset_name: string | null; last_count: number | null; last_synced_at: string | null }

export default function ArmmScreen() {
  const server = useCurrentServerRequired();
  const identifier = server.attributes.identifier;
  const base = `/api/client/servers/${encodeURIComponent(identifier)}/extensions/armm-modsets`;
  const [sets, setSets] = useState<Modset[]>([]);
  const [assignment, setAssignment] = useState<Assignment | null>(null);
  const [chosen, setChosen] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const request = useCallback(async (path: string, method: string = 'GET', body?: unknown) => {
    const response = await fetch(base + path, {
      method, credentials: 'same-origin', headers: {
        'Accept':'application/json', 'Content-Type':'application/json',
        'X-Requested-With':'XMLHttpRequest',
        'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
      }, body: body === undefined ? undefined : JSON.stringify(body),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.error || data.message || `HTTP ${response.status}`);
    return data;
  }, [base]);
  const loadAssignment = useCallback(async () => {
    const data = await request('/assignment') as Assignment;
    setAssignment(data); setChosen(data.modset_id ? String(data.modset_id) : '');
  }, [request]);
  useEffect(() => {
    loadAssignment().catch(e => setError(String(e)));
    request('/modsets').then(data => setSets(data.modsets || [])).catch(e => setError(String(e)));
  }, [loadAssignment, request]);
  const run = async (action: () => Promise<void>) => {
    setBusy(true); setError('');
    try { await action(); } catch (e) { setError(e instanceof Error ? e.message : String(e)); }
    finally { setBusy(false); }
  };
  const loadSets = () => run(async () => {
    const data = await request('/modsets?refresh=1');
    setSets(data.modsets || []);
    toast.success('ARMM modsets loaded');
  });
  const assign = () => run(async () => {
    if (!chosen) throw new Error('Select a modset first.');
    await request('/assignment', 'PUT', { modset_id: Number(chosen) });
    await loadAssignment();
    toast.success('Modset assignment saved');
  });
  const sync = () => run(async () => {
    if (!window.confirm('Replace game.mods in config.json now? The server should be stopped.')) return;
    const data = await request('/sync', 'POST');
    await loadAssignment();
    toast.success(`${data.count} mods applied to config.json`);
  });
  return <div style={{maxWidth:820, margin:'20px auto',padding:'20px',border:'1px solid #60646c',borderRadius:10}}>
    <h1 style={{fontSize:22,fontWeight:700,marginBottom:8}}>ARMM Modset Manager</h1>
    <p style={{marginBottom:20}}>Available modsets load automatically. Select one to assign it to this Reforger server.</p>
    {error && <div role="alert" style={{padding:12,background:'#6b2020',color:'#fff',marginBottom:15,borderRadius:6}}>{error}</div>}
    <button disabled={busy} onClick={loadSets} style={{padding:'9px 15px',border:'1px solid #aaa',borderRadius:6,marginBottom:16}}>Sync Modsets</button>
    <div style={{display:'flex',gap:12,flexWrap:'wrap',marginBottom:16}}>
      <select value={chosen} onChange={e=>setChosen(e.target.value)} disabled={busy} style={{minWidth:270,padding:10,background:'#222',color:'#fff',border:'1px solid #999',borderRadius:5}}>
        <option value="">Select a modset</option>
        {assignment?.modset_id && !sets.some(x=>x.id === assignment.modset_id) && <option value={String(assignment.modset_id)}>{assignment.modset_name || `Modset ID ${assignment.modset_id}`} (saved)</option>}
        {sets.map(s=><option key={s.id} value={String(s.id)}>{s.name} (ID: {s.id})</option>)}
      </select>
      <button disabled={busy || !chosen} onClick={assign} style={{padding:'9px 15px',border:'1px solid #aaa',borderRadius:6}}>Save assignment</button>
      <button disabled={busy || !assignment?.modset_id} onClick={sync} style={{padding:'9px 15px',background:'#276dba',color:'white',borderRadius:6}}>Synchronize config.json</button>
    </div>
    <p>Current assignment: <strong>{assignment?.modset_id ? `${assignment.modset_name || 'Modset'} (ID: ${assignment.modset_id})` : 'none'}</strong></p>
    <p>Last sync: {assignment?.last_synced_at || 'never'} · Mods: {assignment?.last_count ?? '–'}</p>
    <p style={{fontSize:12,opacity:.8,marginTop:14}}>This button updates game.mods directly through Wings. Automatic synchronization on server start is not enabled.</p>
  </div>;
}
