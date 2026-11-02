import React, { useState } from 'react';
import { usePOS } from './POSContext';

export interface KOTCard {
  id: number;
  kot_number: string;
  daily_token: number;
  table_number: string;
  order_type: 'dine_in' | 'takeaway';
  status: 'pending' | 'cooking' | 'ready';
  elapsed_minutes: number;
  items: Array<{
    name: string;
    quantity: number;
    modifiers?: string[];
    special_notes?: string;
  }>;
}

export const KitchenDisplaySystem: React.FC = () => {
  const { setActiveView } = usePOS();

  const [kots, setKots] = useState<KOTCard[]>([
    {
      id: 101,
      kot_number: 'KOT-20261102-0012',
      daily_token: 12,
      table_number: 'T-01',
      order_type: 'dine_in',
      status: 'pending',
      elapsed_minutes: 4,
      items: [
        { name: 'Devilled Chicken with Cashews', quantity: 1, modifiers: ['Extra Spicy', 'No Garlic'] },
        { name: 'Egg Fried Rice (L)', quantity: 1 },
      ],
    },
    {
      id: 102,
      kot_number: 'KOT-20261102-0013',
      daily_token: 13,
      table_number: 'Takeaway #04',
      order_type: 'takeaway',
      status: 'cooking',
      elapsed_minutes: 14,
      items: [
        { name: 'Chicken Kottu Roti', quantity: 2, modifiers: ['Cheese Topping'] },
        { name: 'Iced Coffee', quantity: 2 },
      ],
    },
    {
      id: 103,
      kot_number: 'KOT-20261102-0011',
      daily_token: 11,
      table_number: 'B-02',
      order_type: 'dine_in',
      status: 'ready',
      elapsed_minutes: 22,
      items: [
        { name: 'Mixed Grill Platter', quantity: 1 },
      ],
    },
  ]);

  const updateStatus = async (id: number, nextStatus: 'cooking' | 'ready' | 'served') => {
    try {
      await fetch(`/api/v1/kots/${id}/status`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ status: nextStatus }),
      });
    } catch (e) {
      console.warn('Network offline; updating optimistic UI');
    }

    if (nextStatus === 'served') {
      setKots((prev) => prev.filter((k) => k.id !== id));
    } else {
      setKots((prev) =>
        prev.map((k) => (k.id === id ? { ...k, status: nextStatus } : k))
      );
    }
  };

  return (
    <div className="flex flex-col h-screen bg-slate-900 text-white font-sans overflow-hidden">
      {/* KDS Header Bar */}
      <div className="bg-slate-800 px-6 py-4 flex justify-between items-center border-b border-slate-700">
        <div className="flex items-center space-x-4">
          <span className="text-2xl font-black tracking-wider text-amber-400">KITCHEN DISPLAY SYSTEM</span>
          <span className="bg-slate-700 text-slate-300 text-xs px-2.5 py-1 rounded-full font-bold">
            {kots.length} Active Orders
          </span>
        </div>

        <button
          onClick={() => setActiveView('tables')}
          className="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white font-semibold rounded-lg text-sm transition"
        >
          &larr; Back to Tables
        </button>
      </div>

      {/* 3-Column Kanban Board */}
      <div className="flex-1 grid grid-cols-3 gap-6 p-6 overflow-hidden">
        {/* Column 1: Pending / New Orders */}
        <div className="flex flex-col bg-slate-800/60 rounded-2xl p-4 border border-amber-500/20">
          <div className="flex justify-between items-center mb-4 pb-2 border-b border-slate-700">
            <span className="font-bold text-amber-400 uppercase tracking-wider text-sm flex items-center space-x-2">
              <span className="w-2.5 h-2.5 rounded-full bg-amber-400 inline-block animate-pulse"></span>
              <span>New Orders (Pending)</span>
            </span>
            <span className="text-xs bg-amber-950 text-amber-300 px-2 py-0.5 rounded-full font-bold">
              {kots.filter((k) => k.status === 'pending').length}
            </span>
          </div>

          <div className="flex-1 overflow-y-auto space-y-4 pr-1">
            {kots
              .filter((k) => k.status === 'pending')
              .map((kot) => (
                <div key={kot.id} className="bg-slate-800 rounded-xl p-4 border border-slate-700 shadow-md">
                  <div className="flex justify-between items-start mb-2">
                    <div>
                      <span className="text-2xl font-black text-amber-400">#{kot.daily_token}</span>
                      <span className="ml-2 font-bold text-slate-300 text-sm">{kot.table_number}</span>
                    </div>
                    <span className="text-xs font-semibold text-slate-400">{kot.elapsed_minutes}m ago</span>
                  </div>

                  <div className="space-y-2 py-2 border-t border-b border-slate-700/60 my-2">
                    {kot.items.map((item, idx) => (
                      <div key={idx}>
                        <div className="flex justify-between text-base font-bold text-slate-100">
                          <span>{item.quantity}x {item.name}</span>
                        </div>
                        {item.modifiers && (
                          <p className="text-xs text-amber-300 pl-4">&bull; {item.modifiers.join(', ')}</p>
                        )}
                      </div>
                    ))}
                  </div>

                  <button
                    onClick={() => updateStatus(kot.id, 'cooking')}
                    className="w-full mt-2 py-2 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-lg text-sm transition"
                  >
                    Start Cooking &rarr;
                  </button>
                </div>
              ))}
          </div>
        </div>

        {/* Column 2: Cooking In Progress */}
        <div className="flex flex-col bg-slate-800/60 rounded-2xl p-4 border border-blue-500/20">
          <div className="flex justify-between items-center mb-4 pb-2 border-b border-slate-700">
            <span className="font-bold text-blue-400 uppercase tracking-wider text-sm flex items-center space-x-2">
              <span className="w-2.5 h-2.5 rounded-full bg-blue-400 inline-block"></span>
              <span>Cooking In Progress</span>
            </span>
            <span className="text-xs bg-blue-950 text-blue-300 px-2 py-0.5 rounded-full font-bold">
              {kots.filter((k) => k.status === 'cooking').length}
            </span>
          </div>

          <div className="flex-1 overflow-y-auto space-y-4 pr-1">
            {kots
              .filter((k) => k.status === 'cooking')
              .map((kot) => (
                <div key={kot.id} className="bg-slate-800 rounded-xl p-4 border border-blue-500/40 shadow-md">
                  <div className="flex justify-between items-start mb-2">
                    <div>
                      <span className="text-2xl font-black text-blue-400">#{kot.daily_token}</span>
                      <span className="ml-2 font-bold text-slate-300 text-sm">{kot.table_number}</span>
                    </div>
                    <span className="text-xs font-semibold text-rose-400 animate-pulse">{kot.elapsed_minutes}m in kitchen</span>
                  </div>

                  <div className="space-y-2 py-2 border-t border-b border-slate-700/60 my-2">
                    {kot.items.map((item, idx) => (
                      <div key={idx}>
                        <div className="flex justify-between text-base font-bold text-slate-100">
                          <span>{item.quantity}x {item.name}</span>
                        </div>
                        {item.modifiers && (
                          <p className="text-xs text-blue-300 pl-4">&bull; {item.modifiers.join(', ')}</p>
                        )}
                      </div>
                    ))}
                  </div>

                  <button
                    onClick={() => updateStatus(kot.id, 'ready')}
                    className="w-full mt-2 py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-lg text-sm transition"
                  >
                    Mark as Ready &check;
                  </button>
                </div>
              ))}
          </div>
        </div>

        {/* Column 3: Ready to Serve */}
        <div className="flex flex-col bg-slate-800/60 rounded-2xl p-4 border border-emerald-500/20">
          <div className="flex justify-between items-center mb-4 pb-2 border-b border-slate-700">
            <span className="font-bold text-emerald-400 uppercase tracking-wider text-sm flex items-center space-x-2">
              <span className="w-2.5 h-2.5 rounded-full bg-emerald-400 inline-block"></span>
              <span>Ready for Pickup</span>
            </span>
            <span className="text-xs bg-emerald-950 text-emerald-300 px-2 py-0.5 rounded-full font-bold">
              {kots.filter((k) => k.status === 'ready').length}
            </span>
          </div>

          <div className="flex-1 overflow-y-auto space-y-4 pr-1">
            {kots
              .filter((k) => k.status === 'ready')
              .map((kot) => (
                <div key={kot.id} className="bg-slate-800 rounded-xl p-4 border border-emerald-500/40 shadow-md">
                  <div className="flex justify-between items-start mb-2">
                    <div>
                      <span className="text-2xl font-black text-emerald-400">#{kot.daily_token}</span>
                      <span className="ml-2 font-bold text-slate-300 text-sm">{kot.table_number}</span>
                    </div>
                    <span className="text-xs font-semibold text-emerald-400">Ready</span>
                  </div>

                  <div className="space-y-2 py-2 border-t border-b border-slate-700/60 my-2">
                    {kot.items.map((item, idx) => (
                      <div key={idx} className="text-sm font-semibold text-slate-200">
                        {item.quantity}x {item.name}
                      </div>
                    ))}
                  </div>

                  <button
                    onClick={() => updateStatus(kot.id, 'served')}
                    className="w-full mt-2 py-2 bg-slate-700 hover:bg-slate-600 text-white font-semibold rounded-lg text-sm transition"
                  >
                    Clear / Served &check;
                  </button>
                </div>
              ))}
          </div>
        </div>
      </div>
    </div>
  );
};

