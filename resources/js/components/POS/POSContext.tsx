import React, { createContext, useContext, useState, useEffect } from 'react';

export type BusinessType = 'supermarket' | 'restaurant' | 'pharmacy' | 'retail';

export interface FeatureFlags {
  batch_tracking: boolean;
  barcode_scanning: boolean;
  scale_weight_barcode: boolean;
  loyalty_program: boolean;
  store_credit_ledger: boolean;
  table_management: boolean;
  kitchen_order_ticket: boolean;
  kitchen_display_system: boolean;
  prescription_validation: boolean;
  food_modifiers: boolean;
  [key: string]: boolean;
}

interface POSContextType {
  businessType: BusinessType;
  features: FeatureFlags;
  activeView: 'checkout' | 'tables' | 'kds' | 'held_bills';
  setActiveView: (view: 'checkout' | 'tables' | 'kds' | 'held_bills') => void;
  isFeatureEnabled: (feature: keyof FeatureFlags) => boolean;
}

const POSContext = createContext<POSContextType | undefined>(undefined);

export const POSProvider: React.FC<{
  businessType: BusinessType;
  initialFeatures: FeatureFlags;
  children: React.ReactNode;
}> = ({ businessType, initialFeatures, children }) => {
  const [features] = useState<FeatureFlags>(initialFeatures);
  const [activeView, setActiveView] = useState<'checkout' | 'tables' | 'kds' | 'held_bills'>(
    businessType === 'restaurant' ? 'tables' : 'checkout'
  );

  const isFeatureEnabled = (feature: keyof FeatureFlags): boolean => {
    return !!features[feature];
  };

  return (
    <POSContext.Provider
      value={{
        businessType,
        features,
        activeView,
        setActiveView,
        isFeatureEnabled,
      }}
    >
      {children}
    </POSContext.Provider>
  );
};

export const usePOS = () => {
  const context = useContext(POSContext);
  if (!context) {
    throw new Error('usePOS must be used within a POSProvider');
  }
  return context;
};
