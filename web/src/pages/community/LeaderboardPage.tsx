import { useState } from 'react'
import { Link } from 'react-router-dom'
import {
  Trophy,
  Medal,
  Star,
  MessageSquare,
  CheckCircle,
  ChevronLeft,
  Loader2,
  Crown,
  Award,
  TrendingUp,
} from 'lucide-react'
import { useLeaderboard, useMyHeroProfile } from '@/hooks/useCommunity'
import type { CommunityHero } from '@/types'

const rankColors = [
  { bg: 'bg-amber-100', text: 'text-amber-700', border: 'border-amber-300', icon: Crown },
  { bg: 'bg-slate-100', text: 'text-slate-600', border: 'border-slate-300', icon: Medal },
  { bg: 'bg-orange-100', text: 'text-orange-700', border: 'border-orange-300', icon: Award },
]

export default function LeaderboardPage() {
  const [leaderboardType, setLeaderboardType] = useState<'reputation' | 'recoveries'>('reputation')

  const { data: leaderboard, isLoading } = useLeaderboard(leaderboardType, 20)
  const { data: myProfile } = useMyHeroProfile()

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center gap-4">
        <Link
          to="/community"
          className="p-2 hover:bg-slate-100 rounded-lg transition-colors"
        >
          <ChevronLeft className="h-5 w-5 text-slate-600" />
        </Link>
        <div>
          <h1 className="text-2xl font-bold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
            Community Heroes
          </h1>
          <p className="text-sm text-slate-500">
            Top contributors helping recover stolen devices
          </p>
        </div>
      </div>

      {/* My Stats */}
      {myProfile && (
        <div className="bg-gradient-to-br from-teal-500 to-teal-600 rounded-xl p-6 text-white">
          <div className="flex items-center justify-between mb-4">
            <h2 className="font-semibold">Your Hero Stats</h2>
            {myProfile.rank && (
              <span className="px-3 py-1 bg-white/20 rounded-full text-sm">
                Rank #{myProfile.rank}
              </span>
            )}
          </div>
          <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <div>
              <p className="text-2xl font-bold">{myProfile.reputation_score}</p>
              <p className="text-sm text-teal-100">Reputation</p>
            </div>
            <div>
              <p className="text-2xl font-bold">{myProfile.successful_recoveries}</p>
              <p className="text-sm text-teal-100">Recoveries</p>
            </div>
            <div>
              <p className="text-2xl font-bold">{myProfile.tips_verified}</p>
              <p className="text-sm text-teal-100">Verified Tips</p>
            </div>
            <div>
              <p className="text-2xl font-bold">
                MWK {myProfile.total_bounties_earned.toLocaleString()}
              </p>
              <p className="text-sm text-teal-100">Bounties Earned</p>
            </div>
          </div>
          {myProfile.badges && myProfile.badges.length > 0 && (
            <div className="mt-4 pt-4 border-t border-white/20">
              <p className="text-sm text-teal-100 mb-2">Your Badges</p>
              <div className="flex flex-wrap gap-2">
                {myProfile.badges.map((badge, i) => (
                  <span
                    key={i}
                    className="px-2 py-1 bg-white/20 rounded-full text-xs font-medium"
                  >
                    {badge}
                  </span>
                ))}
              </div>
            </div>
          )}
        </div>
      )}

      {/* Leaderboard Tabs */}
      <div className="bg-white rounded-xl border border-slate-200/60 p-2 inline-flex gap-1">
        <button
          onClick={() => setLeaderboardType('reputation')}
          className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
            leaderboardType === 'reputation'
              ? 'bg-slate-900 text-white'
              : 'text-slate-600 hover:bg-slate-100'
          }`}
        >
          <Star className="inline h-4 w-4 mr-1.5" />
          By Reputation
        </button>
        <button
          onClick={() => setLeaderboardType('recoveries')}
          className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
            leaderboardType === 'recoveries'
              ? 'bg-slate-900 text-white'
              : 'text-slate-600 hover:bg-slate-100'
          }`}
        >
          <CheckCircle className="inline h-4 w-4 mr-1.5" />
          By Recoveries
        </button>
      </div>

      {/* Leaderboard */}
      {isLoading ? (
        <div className="flex items-center justify-center py-20">
          <Loader2 className="h-8 w-8 text-teal-500 animate-spin" />
        </div>
      ) : !leaderboard || leaderboard.length === 0 ? (
        <div className="bg-white rounded-xl border border-slate-200/60 flex flex-col items-center justify-center py-20 text-center">
          <div className="p-4 bg-amber-50 rounded-full mb-4">
            <Trophy className="h-10 w-10 text-amber-400" />
          </div>
          <h3 className="text-lg font-medium text-slate-800 mb-1">No heroes yet</h3>
          <p className="text-sm text-slate-500 max-w-sm">
            Be the first to help recover a device and earn your place on the leaderboard!
          </p>
        </div>
      ) : (
        <div className="space-y-4">
          {/* Top 3 Podium */}
          {leaderboard.length >= 3 && (
            <div className="grid sm:grid-cols-3 gap-4">
              {/* Second Place */}
              <div className="order-2 sm:order-1">
                <HeroCard hero={leaderboard[1]} rank={2} highlight />
              </div>
              {/* First Place */}
              <div className="order-1 sm:order-2">
                <HeroCard hero={leaderboard[0]} rank={1} highlight />
              </div>
              {/* Third Place */}
              <div className="order-3">
                <HeroCard hero={leaderboard[2]} rank={3} highlight />
              </div>
            </div>
          )}

          {/* Rest of leaderboard */}
          <div className="bg-white rounded-xl border border-slate-200/60 overflow-hidden">
            <div className="divide-y divide-slate-100">
              {leaderboard.slice(3).map((hero, index) => (
                <HeroRow key={hero.id} hero={hero} rank={index + 4} />
              ))}
            </div>
          </div>
        </div>
      )}

      {/* How it works */}
      <div className="bg-white rounded-xl border border-slate-200/60 p-6">
        <h3 className="font-semibold text-slate-800 mb-4">How to Earn Points</h3>
        <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
          <div className="flex items-start gap-3">
            <div className="p-2 bg-blue-50 rounded-lg">
              <MessageSquare className="h-5 w-5 text-blue-500" />
            </div>
            <div>
              <p className="font-medium text-slate-800">Submit Tips</p>
              <p className="text-sm text-slate-500">+5 points per tip</p>
            </div>
          </div>
          <div className="flex items-start gap-3">
            <div className="p-2 bg-emerald-50 rounded-lg">
              <CheckCircle className="h-5 w-5 text-emerald-500" />
            </div>
            <div>
              <p className="font-medium text-slate-800">Verified Tips</p>
              <p className="text-sm text-slate-500">+15 points when verified</p>
            </div>
          </div>
          <div className="flex items-start gap-3">
            <div className="p-2 bg-amber-50 rounded-lg">
              <Trophy className="h-5 w-5 text-amber-500" />
            </div>
            <div>
              <p className="font-medium text-slate-800">Device Recovery</p>
              <p className="text-sm text-slate-500">+50 points per recovery</p>
            </div>
          </div>
          <div className="flex items-start gap-3">
            <div className="p-2 bg-purple-50 rounded-lg">
              <TrendingUp className="h-5 w-5 text-purple-500" />
            </div>
            <div>
              <p className="font-medium text-slate-800">Consistent Help</p>
              <p className="text-sm text-slate-500">Bonus for monthly activity</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}

function HeroCard({ hero, rank }: { hero: CommunityHero; rank: number; highlight?: boolean }) {
  const rankStyle = rankColors[rank - 1] || { bg: 'bg-slate-50', text: 'text-slate-600', border: 'border-slate-200', icon: Star }
  const IconComponent = rankStyle.icon

  return (
    <div className={`bg-white rounded-xl border-2 ${rankStyle.border} p-6 text-center ${rank === 1 ? 'sm:-mt-4' : ''}`}>
      <div className={`inline-flex items-center justify-center w-12 h-12 ${rankStyle.bg} rounded-full mb-4`}>
        <IconComponent className={`h-6 w-6 ${rankStyle.text}`} />
      </div>
      <div className="w-16 h-16 bg-slate-200 rounded-full mx-auto mb-3 flex items-center justify-center text-2xl font-bold text-slate-500">
        {hero.display_name.charAt(0).toUpperCase()}
      </div>
      <h3 className="font-semibold text-slate-800">{hero.display_name}</h3>
      <p className="text-sm text-slate-500 mb-4">Rank #{rank}</p>

      <div className="space-y-2 text-sm">
        <div className="flex items-center justify-between">
          <span className="text-slate-500">Reputation</span>
          <span className="font-semibold text-slate-800">{hero.reputation_score}</span>
        </div>
        <div className="flex items-center justify-between">
          <span className="text-slate-500">Recoveries</span>
          <span className="font-semibold text-emerald-600">{hero.successful_recoveries}</span>
        </div>
        <div className="flex items-center justify-between">
          <span className="text-slate-500">Bounties</span>
          <span className="font-semibold text-slate-800">
            MWK {hero.total_bounties_earned.toLocaleString()}
          </span>
        </div>
      </div>

      {hero.badges && hero.badges.length > 0 && (
        <div className="mt-4 pt-4 border-t border-slate-100 flex flex-wrap justify-center gap-1">
          {hero.badges.slice(0, 3).map((badge, i) => (
            <span
              key={i}
              className="px-2 py-0.5 bg-slate-100 text-slate-600 rounded-full text-xs"
            >
              {badge}
            </span>
          ))}
        </div>
      )}
    </div>
  )
}

function HeroRow({ hero, rank }: { hero: CommunityHero; rank: number }) {
  return (
    <div className="flex items-center gap-4 p-4 hover:bg-slate-50 transition-colors">
      <div className="w-8 text-center">
        <span className="text-sm font-medium text-slate-500">#{rank}</span>
      </div>
      <div className="w-10 h-10 bg-slate-200 rounded-full flex items-center justify-center text-sm font-bold text-slate-500">
        {hero.display_name.charAt(0).toUpperCase()}
      </div>
      <div className="flex-1 min-w-0">
        <p className="font-medium text-slate-800 truncate">{hero.display_name}</p>
        <p className="text-xs text-slate-500">
          {hero.tips_submitted} tips · {hero.successful_recoveries} recoveries
        </p>
      </div>
      <div className="text-right">
        <p className="font-semibold text-slate-800">{hero.reputation_score}</p>
        <p className="text-xs text-slate-500">points</p>
      </div>
    </div>
  )
}
