import {
  AudioLines,
  Cable,
  Radio,
  ScreenShare,
  Speaker,
  Tv,
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
    name: 'Televisions',
    eyebrow: 'See every detail',
    description: 'Carefully selected televisions for sport, film, family time, and more.',
    icon: Tv,
    tone: 'dark',
  },
  {
    name: 'Home theatres',
    eyebrow: 'Bring cinema home',
    description: 'Complete surround-sound systems that make every night feel bigger.',
    icon: ScreenShare,
    tone: 'light',
  },
  {
    name: 'Bluetooth speakers',
    eyebrow: 'Take the music with you',
    description: 'Portable wireless sound for home, gatherings, and everyday listening.',
    icon: Speaker,
    tone: 'teal',
  },
  {
    name: 'Soundbars',
    eyebrow: 'Clear, room-filling sound',
    description: 'A simple way to give dialogue, music, and action more presence.',
    icon: AudioLines,
    tone: 'light',
  },
  {
    name: 'TV accessories',
    eyebrow: 'Finish the room',
    description: 'Wall mounts, remotes, and essentials for a clean, reliable setup.',
    icon: Cable,
    tone: 'dark',
  },
  {
    name: 'Audio accessories',
    eyebrow: 'Connect everything',
    description: 'Useful audio additions chosen for compatibility and lasting value.',
    icon: Radio,
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
