import React, { useState } from 'react';
import { usePOS } from './POSContext';

export interface TableItem {
  id: number;
  table_number: string;
  zone: string;
  seating_capacity: number;
  status: 'available' | 'occupied' | 'reserved' | 'billed';
  active_bill_amount?: number;
  active_kot_count?: number;
  occupied_since?: string;
}

export const RestaurantTableView: React.FC = () => {
  const { setActiveView } = usePOS();
  const [selectedZone, setSelectedZone] = useState<string>('All');
  const [selectedTable, setSelectedTable] = useState<TableItem | null>(null);

  // Mock initial tables for restaurant layout
  const [tables] = useState<TableItem[]>([
    { id: 1, table_number: 'T-01', zone: 'Main Dining', seating_capacity: 4, status: 'occupied', active_bill_amount: 5400, active_kot_count: 2, occupied_since: '28m' },
    { id: 2, table_number: 'T-02', zone: 'Main Dining', seating_capacity: 2, status: 'available' },
    { id: 3, table_number: 'T-03', zone: 'Main Dining', seating_capacity: 4, status: 'billed', active_bill_amount: 8900, occupied_since: '45m' },
    { id: 4, table_number: 'T-04', zone: 'Main Dining', seating_capacity: 6, status: 'reserved' },
    { id: 5, table_number: 'B-01', zone: 'Balcony', seating_capacity: 4, status: 'available' },
    { id: 6, table_number: 'B-02', zone: 'Balcony', seating_capacity: 4, status: 'occupied', active_bill_amount: 3200, active_kot_count: 1, occupied_since: '12m' },
    { id: 7, table_number: 'V-01', zone: 'VIP Lounge', seating_capacity: 10, status: 'available' },
  ]);

  const zones = ['All', 'Main Dining', 'Balcony', 'VIP Lounge'];

  const filteredTables = selectedZone === 'All'
    ? tables
    : tables.filter((t) => t.zone === selectedZone);

  const getStatusBadge = (status: TableItem['status']) => {
    switch (status) {
      case 'available':
        return <span className="bg-emerald-100 text-emerald-800 text-xs px-2 py-0.5 rounded-full font-semibold">Available</span>;
      case 'occupied':
        return <span className="bg-rose-100 text-rose-800 text-xs px-2 py-0.5 rounded-full font-semibold">Occupied</span>;
      case 'billed':
        return <span className="bg-blue-100 text-blue-800 text-xs px-2 py-0.5 rounded-full font-semibold">Bill Requested</span>;
      case 'reserved':
        return <span className="bg-amber-100 text-amber-800 text-xs px-2 py-0.5 rounded-full font-semibold">Reserved</span>;
    }
  };

  return (
    <div className="flex h-screen bg-slate-100 overflow-hidden font-sans">
      <div className="flex-1 flex flex-col p-6 space-y-6">
        {/* Top Header & Zone Filters */}
        <div className="bg-white p-4 rounded-xl shadow-sm border border-slate-200 flex justify-between items-center">
          <div className="flex space-x-2">
            {zones.map((zone) => (
              <button
                key={zone}
                onClick={() => setSelectedZone(zone)}
                className={`px-4 py-2 rounded-lg text-sm font-semibold transition ${
                  selectedZone === zone
                    ? 'bg-blue-600 text-white shadow'
                    : 'bg-slate-100 text-slate-700 hover:bg-slate-200'
                }`}
              >
                {zone}
              </button>
            ))}
          </div>

          <div className="flex items-center space-x-3">
            <button
              onClick={() => setActiveView('kds')}
              className="px-4 py-2 bg-slate-800 hover:bg-slate-900 text-white text-sm font-semibold rounded-lg flex items-center space-x-2 shadow"
            >
              <span>🍳 Kitchen Display System (KDS)</span>
            </button>
          </div>
        </div>

        {/* Table Grid Floor Plan */}
        <div className="flex-1 overflow-y-auto">
          <div className="grid grid-cols-4 gap-6">
            {filteredTables.map((table) => (
              <div
                key={table.id}
                onClick={() => setSelectedTable(table)}
                className={`p-5 rounded-2xl border-2 transition cursor-pointer flex flex-col justify-between h-48 bg-white shadow-sm hover:shadow-md ${
                  table.status === 'occupied'
                    ? 'border-rose-300 hover:border-rose-400'
                    : table.status === 'available'
                    ? 'border-emerald-200 hover:border-emerald-400'
                    : table.status === 'billed'
                    ? 'border-blue-300 hover:border-blue-400'
                    : 'border-amber-200 hover:border-amber-400'
                }`}
              >
                <div className="flex justify-between items-start">
                  <div>
                    <span className="text-2xl font-black text-slate-800">{table.table_number}</span>
                    <p className="text-xs text-slate-500">{table.zone} &bull; {table.seating_capacity} Seats</p>
                  </div>
                  {getStatusBadge(table.status)}
                </div>

                {table.status === 'occupied' || table.status === 'billed' ? (
                  <div className="bg-slate-50 p-2.5 rounded-lg border border-slate-100 flex justify-between items-center">
                    <div>
                      <p className="text-[10px] uppercase font-bold text-slate-400">Current Bill</p>
                      <p className="text-sm font-bold text-slate-900">Rs. {table.active_bill_amount?.toLocaleString()}</p>
                    </div>
                    <div className="text-right">
                      <p className="text-[10px] uppercase font-bold text-slate-400">Active KOTs</p>
                      <p className="text-xs font-semibold text-rose-600">{table.active_kot_count} in Kitchen</p>
                    </div>
                  </div>
                ) : (
                  <div className="flex items-center text-xs text-slate-400">
                    <span>Tap to take order</span>
                  </div>
                )}

                <div className="flex justify-between items-center text-xs text-slate-400 border-t border-slate-100 pt-2">
                  <span>{table.occupied_since ? `Seated ${table.occupied_since} ago` : 'Ready for guests'}</span>
                  <span className="font-semibold text-blue-600">Open &rarr;</span>
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>

      {/* Slide-over Table Actions Drawer */}
      {selectedTable && (
        <div className="w-96 bg-white border-l border-slate-200 p-6 flex flex-col justify-between shadow-xl">
          <div>
            <div className="flex justify-between items-center border-b pb-4 mb-4">
              <div>
                <h3 className="text-xl font-black text-slate-800">{selectedTable.table_number} Details</h3>
                <p className="text-xs text-slate-500">{selectedTable.zone} &bull; {selectedTable.seating_capacity} Person Table</p>
              </div>
              <button
                onClick={() => setSelectedTable(null)}
                className="text-slate-400 hover:text-slate-600 font-bold text-lg"
              >
                &times;
              </button>
            </div>

            <div className="space-y-4">
              <div className="p-3 bg-slate-50 rounded-lg">
                <span className="text-xs text-slate-400 uppercase font-semibold">Status</span>
                <div className="mt-1">{getStatusBadge(selectedTable.status)}</div>
              </div>

              {selectedTable.status === 'occupied' && (
                <div className="space-y-2">
                  <h4 className="text-xs font-bold uppercase text-slate-400">Active Order Items</h4>
                  <ul className="text-sm space-y-1.5 text-slate-700">
                    <li className="flex justify-between">
                      <span>1x Grilled Salmon (No Pepper)</span>
                      <span className="font-semibold">Rs. 2,800.00</span>
                    </li>
                    <li className="flex justify-between">
                      <span>2x Virgin Mojito</span>
                      <span className="font-semibold">Rs. 1,600.00</span>
                    </li>
                    <li className="flex justify-between">
                      <span>1x Seafood Fried Rice (Extra Spicy)</span>
                      <span className="font-semibold">Rs. 1,000.00</span>
                    </li>
                  </ul>
                </div>
              )}
            </div>
          </div>

          <div className="space-y-3 pt-6 border-t border-slate-100">
            <button
              onClick={() => setActiveView('checkout')}
              className="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-xl shadow transition"
            >
              {selectedTable.status === 'available' ? 'Open New Dine-In Order' : 'Add Items to Table'}
            </button>

            {selectedTable.status === 'occupied' && (
              <button
                onClick={() => {
                  alert(`Kitchen Order Ticket printed for ${selectedTable.table_number}`);
                }}
                className="w-full py-2.5 bg-amber-500 hover:bg-amber-600 text-white font-semibold rounded-xl transition"
              >
                Send KOT to Kitchen
              </button>
            )}

            {selectedTable.status === 'occupied' && (
              <button
                onClick={() => setActiveView('checkout')}
                className="w-full py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-xl transition"
              >
                Generate Final Bill & Settle
              </button>
            )}
          </div>
        </div>
      )}
    </div>
  );
};

