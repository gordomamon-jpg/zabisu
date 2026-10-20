<!-- Filtros de las orillas irregulares del diseño Pizarrón (tinta de sellos y papel roto) -->
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <filter id="pz-tinta"><feTurbulence type="fractalNoise" baseFrequency=".9" numOctaves="2" seed="4" result="r"/><feDisplacementMap in="SourceGraphic" in2="r" scale="2.4"/><feComponentTransfer><feFuncA type="discrete" tableValues="0 1 1 1 1 .85 1"/></feComponentTransfer></filter>
    <filter id="pz-borde"><feTurbulence type="fractalNoise" baseFrequency=".05" numOctaves="3" seed="2" result="r"/><feDisplacementMap in="SourceGraphic" in2="r" scale="3"/></filter>
</svg>
