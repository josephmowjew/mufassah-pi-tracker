import { formatMalawiDate } from '@/lib/datetime'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import {
  Trophy,
  Heart,
  Calendar,
  MapPin,
  Smartphone,
  DollarSign,
  ChevronLeft,
  Loader2,
  Star,
  Share2,
  Clock,
  User,
} from 'lucide-react'
import { useSuccessStories, useToggleLike, useCommunityStats } from '@/hooks/useCommunity'
import type { SuccessStory } from '@/types'

export default function SuccessStoriesPage() {
  const [page, setPage] = useState(1)
  const [sort, setSort] = useState<'recent' | 'popular'>('recent')
  const [featured, setFeatured] = useState(false)

  const { data: storiesData, isLoading, error } = useSuccessStories({
    page,
    per_page: 12,
    sort,
    featured: featured || undefined,
  })
  const { data: stats } = useCommunityStats()
  const toggleLike = useToggleLike()

  const stories = storiesData?.data || []
  const meta = storiesData?.meta

  const handleLike = (id: number) => {
    toggleLike.mutate(id)
  }

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
            Success Stories
          </h1>
          <p className="text-sm text-slate-500">
            Celebrating recovered devices and community heroes
          </p>
        </div>
      </div>

      {/* Featured Banner */}
      <div className="bg-gradient-to-br from-amber-500 to-amber-600 rounded-xl p-6 text-white">
        <div className="flex items-center gap-2 mb-2">
          <Trophy className="h-6 w-6" />
          <h2 className="text-xl font-bold">Community Recovery Success</h2>
        </div>
        <p className="text-amber-100 mb-4">
          Thanks to our amazing community, {stats?.total_recoveries || 0} devices have been recovered!
        </p>
        <div className="grid grid-cols-3 gap-4 text-center">
          <div>
            <p className="text-2xl font-bold">{stats?.total_stories || 0}+</p>
            <p className="text-sm text-amber-100">Stories Shared</p>
          </div>
          <div>
            <p className="text-2xl font-bold">{stats?.recovery_rate || 0}%</p>
            <p className="text-sm text-amber-100">Recovery Rate</p>
          </div>
          <div>
            <p className="text-2xl font-bold">
              {stats?.avg_days_to_recover
                ? `${stats.avg_days_to_recover}d`
                : '-'}
            </p>
            <p className="text-sm text-amber-100">Avg Recovery Time</p>
          </div>
        </div>
      </div>

      {/* Filters */}
      <div className="bg-white rounded-xl border border-slate-200/60 p-4 flex flex-wrap items-center gap-3">
        <span className="text-sm text-slate-600">Sort by:</span>
        <button
          onClick={() => setSort('recent')}
          className={`px-3 py-1.5 rounded-lg text-sm font-medium transition-colors ${
            sort === 'recent'
              ? 'bg-slate-900 text-white'
              : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
          }`}
        >
          Most Recent
        </button>
        <button
          onClick={() => setSort('popular')}
          className={`px-3 py-1.5 rounded-lg text-sm font-medium transition-colors ${
            sort === 'popular'
              ? 'bg-slate-900 text-white'
              : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
          }`}
        >
          Most Popular
        </button>
        <div className="w-px h-6 bg-slate-200 mx-2" />
        <button
          onClick={() => setFeatured(!featured)}
          className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium transition-colors ${
            featured
              ? 'bg-amber-500 text-white'
              : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
          }`}
        >
          <Star className="h-3.5 w-3.5" />
          Featured
        </button>
      </div>

      {/* Stories Grid */}
      {isLoading ? (
        <div className="flex items-center justify-center py-20">
          <Loader2 className="h-8 w-8 text-teal-500 animate-spin" />
        </div>
      ) : error ? (
        <div className="bg-white rounded-xl border border-slate-200/60 flex flex-col items-center justify-center py-20 text-center">
          <Trophy className="h-10 w-10 text-red-400 mb-3" />
          <p className="text-slate-600">Failed to load stories</p>
        </div>
      ) : stories.length === 0 ? (
        <div className="bg-white rounded-xl border border-slate-200/60 flex flex-col items-center justify-center py-20 text-center">
          <div className="p-4 bg-amber-50 rounded-full mb-4">
            <Trophy className="h-10 w-10 text-amber-400" />
          </div>
          <h3 className="text-lg font-medium text-slate-800 mb-1">No stories yet</h3>
          <p className="text-sm text-slate-500 max-w-sm">
            Be the first to share your device recovery story!
          </p>
        </div>
      ) : (
        <>
          <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
            {stories.map((story) => (
              <StoryCard key={story.id} story={story} onLike={handleLike} />
            ))}
          </div>

          {/* Pagination */}
          {meta && meta.last_page > 1 && (
            <div className="flex items-center justify-between px-4 py-3 bg-white rounded-xl border border-slate-200/60">
              <p className="text-sm text-slate-500">
                Showing {meta.from} to {meta.to} of {meta.total} stories
              </p>
              <div className="flex items-center gap-2">
                <button
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                  disabled={page === 1}
                  className="px-3 py-1.5 rounded-lg border border-slate-200 text-sm hover:bg-slate-50 disabled:opacity-50"
                >
                  Previous
                </button>
                <span className="text-sm text-slate-600">
                  Page {page} of {meta.last_page}
                </span>
                <button
                  onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))}
                  disabled={page === meta.last_page}
                  className="px-3 py-1.5 rounded-lg border border-slate-200 text-sm hover:bg-slate-50 disabled:opacity-50"
                >
                  Next
                </button>
              </div>
            </div>
          )}
        </>
      )}
    </div>
  )
}

function StoryCard({ story, onLike }: { story: SuccessStory; onLike: (id: number) => void }) {
  return (
    <div className="bg-white rounded-xl border border-slate-200/60 overflow-hidden hover:shadow-md transition-shadow">
      {/* Featured Badge */}
      {story.is_featured && (
        <div className="px-4 py-2 bg-amber-50 flex items-center gap-2">
          <Star className="h-4 w-4 text-amber-500 fill-amber-500" />
          <span className="text-xs font-medium text-amber-700">Featured Story</span>
        </div>
      )}

      {/* Photo */}
      {story.photos && story.photos.length > 0 ? (
        <div className="aspect-video bg-slate-100">
          <img
            src={story.photos[0]}
            alt={story.title}
            className="w-full h-full object-cover"
          />
        </div>
      ) : (
        <div className="aspect-video bg-gradient-to-br from-emerald-100 to-teal-100 flex items-center justify-center">
          <Trophy className="h-12 w-12 text-emerald-400" />
        </div>
      )}

      <div className="p-5">
        {/* Title */}
        <h3 className="font-semibold text-slate-800 mb-2 line-clamp-1">
          {story.title}
        </h3>

        {/* Story excerpt */}
        <p className="text-sm text-slate-600 line-clamp-2 mb-4">
          {story.story}
        </p>

        {/* Device Info */}
        <div className="flex items-center gap-2 mb-3 text-sm text-slate-500">
          <Smartphone className="h-4 w-4" />
          <span>{story.device_description}</span>
        </div>

        {/* Recovery Info */}
        <div className="flex flex-wrap items-center gap-3 text-xs text-slate-500 mb-4">
          {story.days_to_recover && (
            <span className="flex items-center gap-1">
              <Clock className="h-3.5 w-3.5" />
              {story.days_to_recover} day{story.days_to_recover !== 1 ? 's' : ''} to recover
            </span>
          )}
          {story.recovery_city && (
            <span className="flex items-center gap-1">
              <MapPin className="h-3.5 w-3.5" />
              {story.recovery_city}
            </span>
          )}
          {story.bounty_paid && (
            <span className="flex items-center gap-1 text-emerald-600">
              <DollarSign className="h-3.5 w-3.5" />
              MWK {story.bounty_paid.toLocaleString()} bounty
            </span>
          )}
        </div>

        {/* Author & Date */}
        <div className="flex items-center gap-2 text-xs text-slate-400 mb-4">
          <User className="h-3.5 w-3.5" />
          <span>by {story.author?.name || 'Anonymous'}</span>
          <span>·</span>
          <Calendar className="h-3.5 w-3.5" />
          <span>{story.published_at ? formatMalawiDate(story.published_at) : 'Draft'}</span>
        </div>

        {/* Actions */}
        <div className="flex items-center justify-between pt-4 border-t border-slate-100">
          <button
            onClick={() => onLike(story.id)}
            className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium transition-colors ${
              story.is_liked
                ? 'bg-red-50 text-red-600'
                : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
            }`}
          >
            <Heart className={`h-4 w-4 ${story.is_liked ? 'fill-red-500' : ''}`} />
            {story.likes_count}
          </button>
          <div className="flex items-center gap-3 text-xs text-slate-400">
            <span className="flex items-center gap-1">
              <Share2 className="h-3.5 w-3.5" />
              {story.shares_count}
            </span>
          </div>
        </div>
      </div>
    </div>
  )
}
