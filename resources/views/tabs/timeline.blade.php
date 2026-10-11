{{-- The span tree: phases, middleware, the controller and everything placed under the span it ran in. --}}
<x-monocle::panel name="timeline" @click="timelineClick($event)" x-html="timelineHtml" />
