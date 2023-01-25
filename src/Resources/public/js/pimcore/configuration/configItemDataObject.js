pimcore.registerNS('pimcore.plugin.storeExporterDataObject.configuration.configItemDataObject');
pimcore.plugin.storeExporterDataObject.configuration.configItemDataObject = Class.create(pimcore.plugin.datahub.configuration.graphql.configItem, {

    getPanels: function () {
        return [
            this.buildGeneralTab(),
        ];
    },

    initialize: function (data, parent) {
        //TODO make that more generic in datahub
        this.parent = parent;
        this.configName = data.name;
        this.data = data.configuration;
        this.userPermissions = data.userPermissions;
        this.modificationDate = data.modificationDate;

        /**
         * Set writeable to true, if it is undefined.
         * This is done because of backwards compatability to version 6.9-
         * Otherwise the save button would be disabled.
         */
        if (typeof this.data.general.writeable === 'undefined') {
            this.data.general.writeable = true;
        }

        this.tab = Ext.create('Ext.TabPanel', {
            title: this.data.general.name,
            closable: true,
            deferredRender: true,
            forceLayout: true,
            iconCls: "plugin_pimcore_datahub_icon_" + this.data.general.type,
            id: "plugin_pimcore_datahub_configpanel_panel_" + data.name,
            buttons: {
                componentCls: 'plugin_pimcore_datahub_statusbar',
                itemId: 'footer'
            },
            // items: this.getPanels()
        });

        this.tab.columnHeaderStore = Ext.create('Ext.data.Store', {
            fields: ['id', 'dataIndex', 'label'],
            data: data.columnHeaders,
            autoDestroy: false
        });

        this.tab.add(this.getPanels());
        this.tab.setActiveTab(0);

        this.tab.on("activate", this.tabactivated.bind(this));
        this.tab.on("destroy", this.tabdestroy.bind(this));
        this.tab.on('render', this.isValid.bind(this, false));
        this.setupChangeDetector();

        this.parent.configPanel.editPanel.add(this.tab);
        this.parent.configPanel.editPanel.setActiveTab(this.tab);
        this.parent.configPanel.editPanel.updateLayout();
    },
    buildGeneralTab: function() {

    }
}
);