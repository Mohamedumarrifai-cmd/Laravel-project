import Dexie, { Table } from 'dexie';

export interface OfflineSalePayload {
  id?: number;
  offline_client_uuid: string;
  payload: any;
  created_at: string;
  synced: number; // 0 = pending, 1 = synced
  retry_count: number;
  last_error?: string;
}

export interface HeldBill {
  id?: number;
  tag: string;
  customer?: any;
  items: any[];
  table_id?: number;
  held_at: string;
}

class POSDatabase extends Dexie {
  public offlineSales!: Table<OfflineSalePayload, number>;
  public heldBills!: Table<HeldBill, number>;

  constructor() {
    super('EnterprisePOSDB');
    this.version(1).stores({
      offlineSales: '++id, offline_client_uuid, synced, created_at',
      heldBills: '++id, tag, held_at',
    });
  }
}

export const posDb = new POSDatabase();

/**
 * Sync Engine: Periodically pushes offline sales to Laravel backend when internet recovers.
 */
export async function syncOfflineSales(apiEndpoint: string, authToken: string) {
  const pendingSales = await posDb.offlineSales.where('synced').equals(0).toArray();

  if (pendingSales.length === 0) return;

  for (const sale of pendingSales) {
    try {
      const response = await fetch(apiEndpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${authToken}`,
          'Accept': 'application/json',
        },
        body: JSON.stringify({
          ...sale.payload,
          offline_client_uuid: sale.offline_client_uuid,
        }),
      });

      if (response.ok) {
        await posDb.offlineSales.update(sale.id!, { synced: 1 });
      } else {
        const errorData = await response.json();
        await posDb.offlineSales.update(sale.id!, {
          retry_count: sale.retry_count + 1,
          last_error: errorData.message || 'Server error',
        });
      }
    } catch (err: any) {
      console.warn(`[Offline Sync] Network unavailable. Will retry.`, err);
      break; // Pause sync until next connectivity event
    }
  }
}

// Background event listener for network recovery
if (typeof window !== 'undefined') {
  window.addEventListener('online', () => {
    const token = localStorage.getItem('pos_token') || '';
    if (token) {
      syncOfflineSales('/api/v1/pos/checkout', token);
    }
  });
}
