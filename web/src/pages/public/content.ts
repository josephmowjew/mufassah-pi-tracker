import {
  Headphones,
  Laptop,
  MapPin,
  ShieldCheck,
  Smartphone,
  Speaker,
  Watch,
  type LucideIcon,
} from 'lucide-react'

export interface ProductHighlight {
  name: string
  eyebrow: string
  description: string
  icon: LucideIcon
  tone: 'light' | 'dark' | 'teal'
}

export const productHighlights: ProductHighlight[] = [
  {
    name: 'Smartphones',
    eyebrow: 'Stay connected',
    description: 'Reliable devices for everyday work, creativity, and connection.',
    icon: Smartphone,
    tone: 'dark',
  },
  {
    name: 'Computing',
    eyebrow: 'Do more',
    description: 'Thoughtfully selected laptops for study, business, and life.',
    icon: Laptop,
    tone: 'light',
  },
  {
    name: 'Audio',
    eyebrow: 'Hear every detail',
    description: 'Headphones and speakers designed around how you listen.',
    icon: Headphones,
    tone: 'teal',
  },
  {
    name: 'Wearables',
    eyebrow: 'Move smarter',
    description: 'Connected watches that make the important things easier to reach.',
    icon: Watch,
    tone: 'light',
  },
  {
    name: 'Smart accessories',
    eyebrow: 'Complete your setup',
    description: 'Practical additions selected for compatibility and lasting value.',
    icon: Speaker,
    tone: 'dark',
  },
  {
    name: 'Device protection',
    eyebrow: 'Confidence built in',
    description: 'Tracking-ready support that helps protect what matters.',
    icon: ShieldCheck,
    tone: 'teal',
  },
]

export const values = [
  {
    title: 'Quality, carefully chosen',
    copy: 'We focus on useful technology, dependable performance, and products we are proud to support.',
  },
  {
    title: 'Advice without the jargon',
    copy: 'Our team helps you understand the real differences, so you can choose with confidence.',
  },
  {
    title: 'Support that stays close',
    copy: 'From setup to protection, our local teams are ready to help after the purchase too.',
  },
]

export const openRoles = [
  {
    title: 'Retail Sales Consultant',
    location: 'Mufasah branches',
    type: 'Full time',
    description: 'Help customers find the right technology through thoughtful questions, clear advice, and excellent service.',
    icon: Smartphone,
  },
  {
    title: 'Customer Support Associate',
    location: 'Mufasah branches',
    type: 'Full time',
    description: 'Guide customers through setup, product support, warranties, and device-protection services.',
    icon: ShieldCheck,
  },
  {
    title: 'Store & Inventory Assistant',
    location: 'Mufasah branches',
    type: 'Full time',
    description: 'Keep products moving accurately and make every in-store experience feel organised and effortless.',
    icon: MapPin,
  },
]
