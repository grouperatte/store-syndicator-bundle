pimcore.registerNS('pimcore.plugin.storeExporterDataObject.configuration.configItemDataObject');
pimcore.plugin.storeExporterDataObject.configuration.configItemDataObject = Class.create(pimcore.plugin.datahub.configuration.graphql.configItem, {

    config: {
        attributeStore: null
    },

    urlSave: Routing.generate('pimcore_storesyndicator_configdataobject_save'),
    
    getPanels: function () {
        return [
            this.buildGeneralTab(),
            this.buildAttributeMappingTab(),
            this.buildAccessTab()
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
            }
        });

        this.tab.columnHeaderStore = Ext.create('Ext.data.Store', {
            fields: ['id', 'dataIndex', 'label'],
            data: data.columnHeaders,
            autoDestroy: false
        });

        this.tab.add(this.getPanels());
        this.tab.setActiveTab(0);

        // this.tab.on("activate", this.tabactivated.bind(this));
        // this.tab.on("destroy", this.tabdestroy.bind(this));
        // this.tab.on('render', this.isValid.bind(this, false));
        //this.setupChangeDetector();

        this.parent.configPanel.editPanel.add(this.tab);
        this.parent.configPanel.editPanel.setActiveTab(this.tab);
        this.parent.configPanel.editPanel.updateLayout();

        var footer = this.tab.getDockedComponent('footer');

        footer.removeAll();

        footer.add('->');

        let saveButtonConfig = {
            text: t("save"),
            iconCls: "pimcore_icon_apply",
            disabled: !this.data.general.writeable || !this.userPermissions.update,
            handler: this.save.bind(this)
        };
        if(!this.data.general.writeable) {
            saveButtonConfig.tooltip = t("config_not_writeable");
        }
        footer.add(saveButtonConfig);
    },
    buildGeneralTab: function() {
        this.generalForm = Ext.create('Ext.form.FormPanel', {
            bodyStyle: "padding:10px;",
            autoScroll: true,
            defaults: {
                labelWidth: 200,
                width: 600
            },
            border: false,
            title: t('plugin_pimcore_datahub_configpanel_item_general'),
            items: [
                {
                    xtype: "checkbox",
                    fieldLabel: t("active"),
                    name: "active",
                    inputValue: true,
                    value: this.data.general && this.data.general.hasOwnProperty("active") ? this.data.general.active : false
                },
                {
                    xtype: "textfield",
                    fieldLabel: t("type"),
                    name: "type",
                    value: t("plugin_pimcore_datahub_type_" + this.data.general.type),
                    readOnly: true
                },
                {
                    xtype: "textfield",
                    fieldLabel: t("name"),
                    name: "name",
                    value: this.data.general.name,
                    readOnly: true
                },
                {
                    name: "description",
                    fieldLabel: t("description"),
                    xtype: "textarea",
                    height: 100,
                    value: this.data.general.description
                },
            ]
        });
        return this.generalForm;
    },
    buildAttributeMappingTab: function() {
        if(!this.attributeStore){
            this.attributeStore = Ext.create('Ext.data.Store', {
                fields: ['local field', 'remote field'],
                data: this.data.attributeMap
            });
        }
        if(!this.localAttributesStore){
            this.localAttributesStore = Ext.create('Ext.data.Store', {
                fields: ['name'],
                proxy: {
                    method: 'GET',
                    url: Routing.generate('pimcore_storesyndicator_attributes_get_local'),
                    noCache: false,
                    type: 'ajax',
                    root: 'result',
                    totalProperty: 'total',
                },
                autoLoad: true
            });
        }if(!this.remoteAttributesStore){
            this.remoteAttributesStore = Ext.create('Ext.data.Store', {
                fields: ['name'],
                proxy: {
                    method: 'GET',
                    url: Routing.generate('pimcore_storesyndicator_attributes_get_remote'),
                    noCache: false,
                    type: 'ajax',
                    root: 'result',
                    totalProperty: 'total',
                },
                autoLoad: true
            });
        }
        grid = Ext.create('Ext.grid.Panel', {
            title: t('plugin_pimcore_datahub_configpanel_item_attribute_mapping'),
            plugins: [Ext.create('Ext.grid.plugin.CellEditing', {
                clicksToEdit: 1,
                delay: 10
            })],
            tbar: [{
                text: 'Add Mapping',
                handler: function () {
                    let rec = {'local field': "", 'remote field': ""};
                    this.attributeStore.insert(0, rec);
                }.bind(this)
            }],
            store: this.attributeStore,
        
            columns: [
                {
                    text: 'local field',
                    dataIndex: 'local field',
                    width: 200,
                    editor: {
                        xtype: 'combobox',
                        queryMode: 'local',
                        valueField: 'name',
                        displayField: 'name',
                        store: this.localAttributesStore,
                        listeners: {
                            change: function (thisCmb, newValue, oldValue) {
        
                            },
                            beforerender: function (thisCmb, eOpts) {
        
                            }
                        }
                    }
                },
                {
                    text: 'remote field',
                    dataIndex: 'remote field',
                    width: 200,
                    editor: {
                        xtype: 'combobox',
                        queryMode: 'local',
                        valueField: 'name',
                        displayField: 'name',
                        store: this.remoteAttributesStore,
                        listeners: {
                            change: function (thisCmb, newValue, oldValue) {
        
                            },
                            beforerender: function (thisCmb, eOpts) {
        
                            }
                        }
                    }
                }
            ],
        });
        this.attributeMappingForm = Ext.create('Ext.form.FormPanel', {
            bodyStyle: "padding:10px;",
            autoScroll: true,
            defaults: {
                labelWidth: 200,
                width: 600
            },
            border: false,
            title: t('plugin_pimcore_datahub_configpanel_item_attribute_mapping'),
            items: [
                grid
            ],
            buttons: [{
                text: t("plugin_pimcore_datahub_configpanel_item_reload_fields"),
                handler: function(){
                    this.localAttributesStore.load();
                    this.remoteAttributesStore.load();
                }.bind(this)
            }]
        });
        return this.attributeMappingForm;
    },
    buildAccessTab: function(){
        this.accessForm = Ext.create('Ext.form.FormPanel', {
            bodyStyle: "padding:10px;",
            autoScroll: true,
            defaults: {
                labelWidth: 200,
                width: 600
            },
            border: false,
            title: t('plugin_pimcore_datahub_configpanel_item_access'),
            items: [
                {
                    name: "host",
                    fieldLabel: t("plugin_pimcore_datahub_configpanel_item_shopify_host"),
                    xtype: "textfield",
                    value: this.data.APIAccess.host
                },
                {
                    name: "token",
                    fieldLabel: t("plugin_pimcore_datahub_configpanel_item_shopify_token"),
                    xtype: "textfield",
                    value: this.data.APIAccess.token
                },{
                    name: "key",
                    fieldLabel: t("plugin_pimcore_datahub_configpanel_item_shopify_key"),
                    xtype: "textfield",
                    value: this.data.APIAccess.key
                },{
                    name: "secret",
                    fieldLabel: t("plugin_pimcore_datahub_configpanel_item_shopify_secret"),
                    xtype: "textfield",
                    value: this.data.APIAccess.secret
                },
            ]
        });
        return this.accessForm;
    },
    save: function(){
        var saveData = this.getSaveData();

        Ext.Ajax.request({
            url: this.urlSave,
            params: {
                data: JSON.stringify(saveData),
                modificationDate: this.modificationDate
            },
            method: "post",
            success: function (response) {
                var rdata = Ext.decode(response.responseText);
                if (rdata && rdata.success) {
                    this.modificationDate = rdata.modificationDate;
                    this.saveOnComplete();
                }
                else if(rdata && rdata.permissionError) {
                        pimcore.helpers.showNotification(t("error"), t("plugin_pimcore_datahub_configpanel_item_saveerror_permissions"), "error");
                        this.tab.setActiveTab(this.tab.items.length-1);
                } else {
                    pimcore.helpers.showNotification(t("error"), t("plugin_pimcore_datahub_configpanel_item_saveerror"), "error", t(rdata.message));
                }
            }.bind(this)
        });
    },
    getSaveData: function() {
        let saveData = {};
        let store = this.attributeStore;

        var gridData = [];
        store.each(function(r) {
            gridData.push(r.getData());
        });

        saveData['general'] = this.generalForm.getValues();
        saveData['attributeMap'] = gridData;
        saveData['APIAccess'] = this.accessForm.getValues();
        return saveData;
    },
    saveOnComplete: function () {
        this.parent.configPanel.tree.getStore().load({
            node: this.parent.configPanel.tree.getRootNode()
        });

        pimcore.helpers.showNotification(t("success"), t("plugin_pimcore_datahub_configpanel_item_save_success"), "success");

        this.resetChanges();
    },
});
