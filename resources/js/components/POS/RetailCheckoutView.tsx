import React, { useState, useEffect, useRef } from 'react';
import { usePOS } from './POSContext';
import { posDb } from '../../services/offlineStore';

export interface CartItem {
  product_id: number;
  name: string;
  sku: string;
  barcode?: string;
  unit_price: number;
  quantity: number;
  discount_amount: number;
  vat_applicable: boolean;
  sscl_applicable: boolean;
  batch_id?: number;
  batch_number?: string;
  expiry_date?: string;
  modifiers?: Array<{ name: string; price: number }>;
}

export const RetailCheckoutView: React.FC = () => {
  const { isFeatureEnabled } = usePOS();
  const [cart, setCart] = useState<CartItem[]>([]);
  const [searchQuery, setSearchQuery] = useState('');
  const [barcodeBuffer, setBarcodeBuffer] = useState('');
  const [lastKeystrokeTime, setLastKeystrokeTime] = useState(0);
  const [isPaymentModalOpen, setIsPaymentModalOpen] = useState(false);
  const [discountPercent, setDiscountPercent] = useState(0);
  const [managerPin, setManagerPin] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);

  // Barcode scanner listener (detects keyboard emulation hardware scanners)
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      // Don't intercept inputs inside input fields unless it's Enter
      if ((e.target as HTMLElement).tagName === 'INPUT' && (e.target as HTMLElement).id !== 'quick-search') {
        return;
      }

      const currentTime = Date.now();
      const diff = currentTime - lastKeystrokeTime;
      setLastKeystrokeTime(currentTime);

      if (e.key === 'Enter') {
        if (barcodeBuffer.length >= 4) {
          handleScannedBarcode(barcodeBuffer);
        }
        setBarcodeBuffer('');
      } else if (e.key.length === 1) {
        // Scanner typists send characters < 40ms apart
        if (diff > 80) {
          setBarcodeBuffer(e.key);
        } else {
          setBarcodeBuffer((prev) => prev + e.key);
        }
      }
    };

    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [barcodeBuffer, lastKeystrokeTime]);

  const handleScannedBarcode = (barcode: string) => {
    // Check for Supermarket Scale Barcode Pattern (e.g., 20XXXXXWWWWWC)
    // 20 = Prefix, XXXXX = Item Code, WWWWW = Weight in grams
    if (isFeatureEnabled('scale_weight_barcode') && barcode.startsWith('20') && barcode.length === 13) {
      const itemSku = barcode.substring(2, 7);
      const weightInGrams = parseInt(barcode.substring(7, 12), 10);
      const weightInKg = weightInGrams / 1000;
      lookupAndAddToCart(itemSku, weightInKg);
      return;
    }

    lookupAndAddToCart(barcode, 1);
  };

  const lookupAndAddToCart = async (identifier: string, qty: number = 1) => {
    try {
      const res = await fetch(`/api/v1/pos/products/lookup?code=${encodeURIComponent(identifier)}`);
      if (res.ok) {
        const product = await res.json();
        addItemToCart(product, qty);
      }
    } catch (err) {
      console.error('Failed to lookup product', err);
    }
  };

  const addItemToCart = (product: any, qty: number = 1) => {
    setCart((prevCart) => {
      const existingIndex = prevCart.findIndex(
        (item) => item.product_id === product.id && item.batch_id === product.batch_id
      );

      if (existingIndex > -1) {
        const updated = [...prevCart];
        updated[existingIndex].quantity += qty;
        return updated;
      }

      return [
        ...prevCart,
        {
          product_id: product.id,
          name: product.name,
          sku: product.sku,
          unit_price: product.selling_price,
          quantity: qty,
          discount_amount: 0,
          vat_applicable: product.vat_applicable ?? true,
          sscl_applicable: product.sscl_applicable ?? true,
          batch_id: product.batch_id,
          batch_number: product.batch_number,
          expiry_date: product.expiry_date,
        },
      ];
    });
  };

  // Tax and Total Computations (Sri Lanka: VAT 18%, SSCL 2.5%)
  const subtotal = cart.reduce((acc, item) => acc + item.unit_price * item.quantity, 0);
  const discountAmount = (subtotal * discountPercent) / 100;
  const taxableBase = Math.max(0, subtotal - discountAmount);
  const ssclAmount = taxableBase * 0.025; // 2.5% SSCL
  const vatAmount = (taxableBase + ssclAmount) * 0.18; // 18% VAT on (Base + SSCL)
  const grandTotal = Math.round(taxableBase + ssclAmount + vatAmount);

  // Hold current cart to IndexedDB
  const handleHoldBill = async () => {
    if (cart.length === 0) return;
    const tag = prompt('Enter a label for this held cart (e.g. Customer Name):') || 'Unlabeled Bill';
    await posDb.heldBills.add({
      tag,
      items: cart,
      held_at: new Date().toISOString(),
    });
    setCart([]);
    alert(`Bill "${tag}" successfully held.`);
  };

  return (
    <div className="flex h-screen bg-slate-100 overflow-hidden font-sans">
      {/* Left Column: Product Search, Catalog & Keypad */}
      <div className="flex-1 flex flex-col p-4 space-y-4">
        {/* Search & Barcode Header */}
        <div className="bg-white p-4 rounded-xl shadow-sm border border-slate-200 flex items-center space-x-3">
          <div className="relative flex-1">
            <input
              id="quick-search"
              type="text"
              placeholder="Scan Barcode (Hardware) or Search Name / SKU..."
              className="w-full pl-10 pr-4 py-2.5 rounded-lg border border-slate-300 focus:outline-none focus:ring-2 focus:ring-blue-600 focus:border-transparent font-medium"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && lookupAndAddToCart(searchQuery, 1)}
            />
            <span className="absolute left-3 top-3 text-slate-400">🔍</span>
          </div>

          <button
            onClick={handleHoldBill}
            disabled={cart.length === 0}
            className="px-4 py-2.5 bg-amber-500 hover:bg-amber-600 text-white font-semibold rounded-lg shadow-sm transition disabled:opacity-50"
          >
            Hold Bill [F4]
          </button>
        </div>

        {/* Quick Category / Item Tiles */}
        <div className="flex-1 bg-white p-4 rounded-xl shadow-sm border border-slate-200 overflow-y-auto">
          <h3 className="text-xs font-bold uppercase text-slate-400 tracking-wider mb-3">Quick Items</h3>
          <div className="grid grid-cols-4 gap-3">
            {[1, 2, 3, 4, 5, 6, 7, 8].map((item) => (
              <button
                key={item}
                onClick={() =>
                  addItemToCart({
                    id: item,
                    name: `Sample Item ${item}`,
                    sku: `SKU-00${item}`,
                    selling_price: 450.0,
                    vat_applicable: true,
                    sscl_applicable: true,
                  })
                }
                className="p-3 bg-slate-50 hover:bg-blue-50 border border-slate-200 hover:border-blue-300 rounded-lg text-left transition flex flex-col justify-between h-24"
              >
                <span className="font-semibold text-slate-800 text-sm line-clamp-2">Sample Item {item}</span>
                <span className="font-bold text-blue-600 text-sm">Rs. 450.00</span>
              </button>
            ))}
          </div>
        </div>
      </div>

      {/* Right Column: Checkout Cart & Financial Summary */}
      <div className="w-[450px] bg-white border-l border-slate-200 flex flex-col shadow-lg">
        {/* Cart Header */}
        <div className="p-4 border-b border-slate-200 flex justify-between items-center bg-slate-50">
          <div>
            <h2 className="text-lg font-bold text-slate-800">Active Sale Cart</h2>
            <p className="text-xs text-slate-500">{cart.length} items in cart</p>
          </div>
          <button
            onClick={() => setCart([])}
            className="text-xs text-red-600 hover:text-red-800 font-semibold uppercase tracking-wider"
          >
            Clear Cart
          </button>
        </div>

        {/* Cart Line Items */}
        <div className="flex-1 overflow-y-auto p-4 space-y-3 divide-y divide-slate-100">
          {cart.length === 0 ? (
            <div className="h-full flex flex-col items-center justify-center text-slate-400 space-y-2">
              <span className="text-4xl">🛒</span>
              <p className="text-sm">Cart is empty. Scan items to begin.</p>
            </div>
          ) : (
            cart.map((item, idx) => (
              <div key={idx} className="pt-2 flex justify-between items-start">
                <div className="flex-1 pr-2">
                  <h4 className="font-semibold text-slate-800 text-sm">{item.name}</h4>
                  <div className="text-xs text-slate-500 flex items-center space-x-2 mt-0.5">
                    <span>
                      {item.quantity} x Rs. {item.unit_price.toFixed(2)}
                    </span>
                    {isFeatureEnabled('batch_tracking') && item.batch_number && (
                      <span className="bg-amber-100 text-amber-800 px-1.5 py-0.5 rounded text-[10px] font-medium">
                        Batch: {item.batch_number} (Exp: {item.expiry_date})
                      </span>
                    )}
                  </div>
                </div>

                <div className="text-right">
                  <span className="font-bold text-slate-900 text-sm">
                    Rs. {(item.quantity * item.unit_price).toFixed(2)}
                  </span>
                  <div className="flex items-center space-x-1 mt-1 justify-end">
                    <button
                      onClick={() => {
                        const updated = [...cart];
                        if (updated[idx].quantity > 1) {
                          updated[idx].quantity -= 1;
                          setCart(updated);
                        } else {
                          setCart(cart.filter((_, i) => i !== idx));
                        }
                      }}
                      className="w-6 h-6 rounded bg-slate-100 hover:bg-slate-200 text-xs font-bold"
                    >
                      -
                    </button>
                    <button
                      onClick={() => {
                        const updated = [...cart];
                        updated[idx].quantity += 1;
                        setCart(updated);
                      }}
                      className="w-6 h-6 rounded bg-slate-100 hover:bg-slate-200 text-xs font-bold"
                    >
                      +
                    </button>
                  </div>
                </div>
              </div>
            ))
          )}
        </div>

        {/* Calculation Summary */}
        <div className="p-4 bg-slate-50 border-t border-slate-200 space-y-2 text-sm">
          <div className="flex justify-between text-slate-600">
            <span>Subtotal:</span>
            <span>Rs. {subtotal.toFixed(2)}</span>
          </div>

          <div className="flex justify-between text-slate-600 items-center">
            <span>Discount (%):</span>
            <input
              type="number"
              min="0"
              max="100"
              value={discountPercent}
              onChange={(e) => setDiscountPercent(parseFloat(e.target.value) || 0)}
              className="w-16 px-1.5 py-0.5 text-right border border-slate-300 rounded text-sm"
            />
          </div>

          <div className="flex justify-between text-slate-500 text-xs">
            <span>SSCL (2.5%):</span>
            <span>Rs. {ssclAmount.toFixed(2)}</span>
          </div>

          <div className="flex justify-between text-slate-500 text-xs">
            <span>VAT (18%):</span>
            <span>Rs. {vatAmount.toFixed(2)}</span>
          </div>

          <div className="flex justify-between font-bold text-slate-900 text-lg pt-2 border-t border-slate-200">
            <span>Grand Total:</span>
            <span className="text-blue-700">Rs. {grandTotal.toFixed(2)}</span>
          </div>

          {/* Action Button */}
          <button
            onClick={() => setIsPaymentModalOpen(true)}
            disabled={cart.length === 0 || isSubmitting}
            className="w-full mt-3 py-3.5 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-xl shadow-md transition disabled:opacity-50 text-base flex justify-center items-center space-x-2"
          >
            <span>💳 Proceed to Payment [Space]</span>
          </button>
        </div>
      </div>
    </div>
  );
};

