import { useEffect } from 'react'
import { MapContainer, TileLayer, Circle, useMapEvents, useMap } from 'react-leaflet'
import 'leaflet/dist/leaflet.css'

interface GeofenceMapProps {
  center: { lat: number; lng: number } | null
  radius: number
  onCenterChange: (center: { lat: number; lng: number }) => void
  onRadiusChange?: (radius: number) => void
  height?: string
}

// Default center (Lilongwe, Malawi)
const DEFAULT_CENTER = { lat: -13.9626, lng: 33.7741 }

// Component to handle map click events
function MapClickHandler({
  onCenterChange,
}: {
  onCenterChange: (center: { lat: number; lng: number }) => void
}) {
  useMapEvents({
    click: (e) => {
      onCenterChange({ lat: e.latlng.lat, lng: e.latlng.lng })
    },
  })
  return null
}

// Component to recenter map when center changes
function MapRecenter({ center }: { center: { lat: number; lng: number } | null }) {
  const map = useMap()

  useEffect(() => {
    if (center) {
      map.flyTo([center.lat, center.lng], map.getZoom(), {
        animate: true,
        duration: 0.5,
      })
    }
  }, [center, map])

  return null
}

// Circle component with visual feedback
function GeofenceCircle({
  center,
  radius,
}: {
  center: { lat: number; lng: number }
  radius: number
}) {
  return (
    <Circle
      center={[center.lat, center.lng]}
      radius={radius}
      pathOptions={{
        color: '#8b5cf6',
        fillColor: '#8b5cf6',
        fillOpacity: 0.2,
        weight: 2,
      }}
    />
  )
}

export default function GeofenceMap({
  center,
  radius,
  onCenterChange,
  onRadiusChange,
  height = '300px',
}: GeofenceMapProps) {
  const mapCenter = center || DEFAULT_CENTER

  return (
    <div className="relative rounded-lg overflow-hidden border border-slate-200">
      <MapContainer
        center={[mapCenter.lat, mapCenter.lng]}
        zoom={15}
        style={{ height, width: '100%' }}
        className="z-0"
      >
        <TileLayer
          attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
          url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
        />
        <MapClickHandler onCenterChange={onCenterChange} />
        <MapRecenter center={center} />
        {center && (
          <GeofenceCircle
            center={center}
            radius={radius}
          />
        )}
      </MapContainer>

      {/* Instructions overlay */}
      {!center && (
        <div className="absolute inset-0 bg-slate-900/40 flex items-center justify-center pointer-events-none">
          <div className="bg-white rounded-lg px-4 py-2 shadow-lg">
            <p className="text-sm text-slate-700 font-medium">
              Click on the map to set geofence center
            </p>
          </div>
        </div>
      )}

      {/* Radius control */}
      {center && onRadiusChange && (
        <div className="absolute bottom-3 left-3 right-3 bg-white rounded-lg shadow-lg p-3 z-[1000]">
          <div className="flex items-center justify-between mb-1">
            <span className="text-xs font-medium text-slate-600">Radius</span>
            <span className="text-xs text-slate-500">{radius}m</span>
          </div>
          <input
            type="range"
            min="50"
            max="5000"
            step="50"
            value={radius}
            onChange={(e) => onRadiusChange(parseInt(e.target.value))}
            className="w-full h-2 bg-slate-200 rounded-lg appearance-none cursor-pointer accent-violet-500"
          />
          <div className="flex justify-between text-xs text-slate-400 mt-1">
            <span>50m</span>
            <span>5km</span>
          </div>
        </div>
      )}
    </div>
  )
}
