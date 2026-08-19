import { create } from 'zustand'

export interface InventoryPinRevealContext {
  challengeId: number
  actionLabel: string
  targetLabel?: string
  expiresAt: string
  notificationId?: string
}

export interface ActionChallengeInfo {
  challenge_id: number
  action: string
  masked_email: string
  expires_at: string
  attempts_remaining: number
  device_count?: number
  financial?: {
    unit_cost: number
    unit_price: number
    total_cost: number
    total_amount: number
    profit_amount: number
    pricing_source: string
  }
}

export interface ActiveInventoryChallenge {
  challenge: ActionChallengeInfo
  title: string
  description: string
  returnPath: string
}

interface RevealedPin {
  pin: string
  expiresAt: string
}

interface InventoryPinState {
  revealContext: InventoryPinRevealContext | null
  revealedPins: Record<number, RevealedPin>
  activeChallenges: Record<number, ActiveInventoryChallenge>
  openReveal: (context: InventoryPinRevealContext) => void
  closeReveal: () => void
  setRevealedPin: (challengeId: number, pin: string, expiresAt: string) => void
  setActiveChallenge: (activeChallenge: ActiveInventoryChallenge) => void
  clearActiveChallenge: (challengeId: number) => void
  clearPin: (challengeId: number) => void
  clearAll: () => void
}

export const useInventoryPinStore = create<InventoryPinState>((set) => ({
  revealContext: null,
  revealedPins: {},
  activeChallenges: {},
  openReveal: (revealContext) => set({ revealContext }),
  closeReveal: () => set({ revealContext: null }),
  setRevealedPin: (challengeId, pin, expiresAt) => set((state) => ({
    revealedPins: {
      ...state.revealedPins,
      [challengeId]: { pin, expiresAt },
    },
  })),
  setActiveChallenge: (activeChallenge) => set((state) => {
    const activeChallenges = Object.fromEntries(
      Object.entries(state.activeChallenges).filter(([, item]) => (
        item.challenge.action !== activeChallenge.challenge.action
        || item.returnPath !== activeChallenge.returnPath
      )),
    )
    activeChallenges[activeChallenge.challenge.challenge_id] = activeChallenge
    return { activeChallenges }
  }),
  clearActiveChallenge: (challengeId) => set((state) => {
    const activeChallenges = { ...state.activeChallenges }
    delete activeChallenges[challengeId]
    return { activeChallenges }
  }),
  clearPin: (challengeId) => set((state) => {
    const revealedPins = { ...state.revealedPins }
    delete revealedPins[challengeId]
    return { revealedPins }
  }),
  clearAll: () => set({ revealContext: null, revealedPins: {}, activeChallenges: {} }),
}))
