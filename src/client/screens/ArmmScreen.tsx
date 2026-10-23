import React, { useCallback, useEffect, useState } from 'react';
import { Alert, Button, Dialog, Select, http, httpErrorToHuman, toast, useCurrentServerRequired } from '@pterodactyl/sdk';

interface Modset { id: number; name: string }
interface Assignment { modset_id: number | null; modset_name: string | null; last_count: number | null; last_synced_at: string | null }
interface ModsetList { modsets: Modset[]; tls_verify: boolean }

// Requests that reach ARMM log in first and may take longer than the panel's default timeout.
const ARMM_TIMEOUT = 60000;

export default function ArmmScreen() {
  const server = useCurrentServerRequired();
  const base = `/api/client/servers/${encodeURIComponent(server.attributes.identifier)}/extensions/armm-modsets`;
  const [sets, setSets] = useState<Modset[]>([]);
  const [tlsVerify, setTlsVerify] = useState(true);
  const [assignment, setAssignment] = useState<Assignment | null>(null);
  const [chosen, setChosen] = useState<number | null>(null);
  const [busy, setBusy] = useState(false);
  const [loadingSets, setLoadingSets] = useState(false);
  const [confirmSync, setConfirmSync] = useState(false);
  const [assignmentError, setAssignmentError] = useState('');
  const [modsetsError, setModsetsError] = useState('');
  const [actionError, setActionError] = useState('');

  const loadAssignment = useCallback(async () => {
    setAssignmentError('');
    try {
      const { data } = await http.get<Assignment>(`${base}/assignment`);
      setAssignment(data);
      setChosen(data.modset_id);
    } catch (e) {
      setAssignmentError(httpErrorToHuman(e));
      throw e;
    }
  }, [base]);

  const loadSets = useCallback(async (refresh: boolean = false) => {
    setLoadingSets(true); setModsetsError('');
    try {
      const { data } = refresh
        ? await http.post<ModsetList>(`${base}/modsets/refresh`, undefined, { timeout: ARMM_TIMEOUT })
        : await http.get<ModsetList>(`${base}/modsets`, { timeout: ARMM_TIMEOUT });
      setSets(data.modsets || []);
      setTlsVerify(data.tls_verify !== false);
    } catch (e) {
      setModsetsError(httpErrorToHuman(e));
      throw e;
    } finally {
      setLoadingSets(false);
    }
  }, [base]);

  useEffect(() => {
    void loadAssignment().catch(() => undefined);
    void loadSets().catch(() => undefined);
  }, [loadAssignment, loadSets]);

  const run = async (action: () => Promise<void>) => {
    setBusy(true); setActionError('');
    try { await action(); } catch (e) { setActionError(httpErrorToHuman(e)); }
    finally { setBusy(false); }
  };
  const refreshSets = async () => {
    setBusy(true); setActionError('');
    try {
      await loadSets(true);
      toast.success('ARMM modsets refreshed');
    } catch {
      // loadSets provides a dedicated modset error message.
    } finally {
      setBusy(false);
    }
  };
  const assign = () => run(async () => {
    if (!chosen) throw new Error('Select a modset first.');
    await http.put(`${base}/assignment`, { modset_id: chosen }, { timeout: ARMM_TIMEOUT });
    await loadAssignment();
    toast.success('Modset assignment saved');
  });
  const sync = () => run(async () => {
    setConfirmSync(false);
    const { data } = await http.post<{ count: number }>(`${base}/sync`, undefined, { timeout: ARMM_TIMEOUT });
    await loadAssignment();
    toast.success(`${data.count} mods applied to config.json`);
  });

  const options = [
    ...(assignment?.modset_id && !sets.some(x => x.id === assignment.modset_id)
      ? [{ value: assignment.modset_id, label: `${assignment.modset_name || `Modset ID ${assignment.modset_id}`} (saved)` }]
      : []),
    ...sets.map(s => ({ value: s.id, label: `${s.name} (ID: ${s.id})` })),
  ];

  return <div style={{ maxWidth: 820, margin: '20px auto', display: 'flex', flexDirection: 'column', gap: 16 }}>
    <div>
      <h1 style={{ fontSize: 22, fontWeight: 700, marginBottom: 8 }}>ARMM Modset Manager</h1>
      <p>Available modsets load automatically. Select one to assign it to this Reforger server.</p>
    </div>
    {!tlsVerify && <Alert type={'warning'}>TLS certificate verification for ARMM is disabled. Ask an administrator to enable it in Admin → Extensions.</Alert>}
    {assignmentError && <Alert type={'danger'} title={'Could not load the current assignment'}>{assignmentError}</Alert>}
    {modsetsError && <Alert type={'danger'} title={'Could not load modsets'}>{modsetsError}</Alert>}
    {actionError && <Alert type={'danger'}>{actionError}</Alert>}
    <div>
      <Button isSecondary isLoading={loadingSets} disabled={busy} onClick={refreshSets}>Refresh Modsets</Button>
    </div>
    <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', alignItems: 'center' }}>
      <div style={{ minWidth: 270, flex: 1 }}>
        <Select value={chosen} onChange={value => setChosen(Number(value))} options={options}
          placeholder={'Select a modset'} disabled={busy || loadingSets} />
      </div>
      <Button isSecondary disabled={busy || loadingSets || !chosen} onClick={assign}>Save assignment</Button>
      <Button disabled={busy || !assignment?.modset_id} onClick={() => setConfirmSync(true)}>Synchronize config.json</Button>
    </div>
    <div>
      <p>Current assignment: <strong>{assignment?.modset_id ? `${assignment.modset_name || 'Modset'} (ID: ${assignment.modset_id})` : 'none'}</strong></p>
      <p>Last sync: {assignment?.last_synced_at || 'never'} · Mods: {assignment?.last_count ?? '–'}</p>
      <p style={{ fontSize: 12, opacity: .8, marginTop: 14 }}>This button updates game.mods directly through Wings. Automatic synchronization on server start is not enabled.</p>
    </div>
    <Dialog.Confirm open={confirmSync} onClose={() => setConfirmSync(false)} pending={busy}
      title={'Synchronize config.json?'} confirm={'Synchronize'} onConfirmed={sync}>
      game.mods in config.json will be replaced with the assigned modset. The server must be stopped; a timestamped backup of the current file is created first.
    </Dialog.Confirm>
  </div>;
}
